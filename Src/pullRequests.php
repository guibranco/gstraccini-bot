<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\lib\HealthChecks;

define("ISSUES", "/issues/");
define("PULLS", "/pulls/");

function handlePullRequest($pullRequest)
{
    global $gitHubUserToken;
    $config = loadConfig();

    $botDashboardUrl = "https://bot.straccini.com/dashboard";
    $prQueryString =
        "?owner=" . $pullRequest->RepositoryOwner .
        "&repo=" . $pullRequest->RepositoryName .
        "&pullRequest=" . $pullRequest->Number;

    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
    $repoPrefix = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName;
    $metadata = array(
        "token" => $token,
        "userToken" => $gitHubUserToken,
        "squashAndMergeComment" => "@dependabot squash and merge",
        "commentsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/comments",
        "pullRequestUrl" => $repoPrefix . PULLS . $pullRequest->Number,
        "reviewsUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/reviews",
        "assigneesUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "requestReviewUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/requested_reviewers",
        "checkRunUrl" => $repoPrefix . "/check-runs",
        "issuesUrl" => $repoPrefix . "/issues",
        "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
        "dashboardUrl" => $botDashboardUrl . $prQueryString
    );

    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);

    if ($pullRequestUpdated->state == "closed") {
        removeIssueWipLabel($metadata, $pullRequest);
    }

    if ($pullRequestUpdated->state != "open") {
        return;
    }

    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated);
    enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config);
    addLabels($metadata, $pullRequest);
    updateBranch($metadata, $pullRequestUpdated);

    $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
    $collaboratorsLogins = array_column(json_decode($collaboratorsResponse->body), "login");

    $botReviewed = false;
    $invokerReviewed = false;

    $reviewsLogins = getReviewsLogins($metadata);
    if (in_array($config->botName . "[bot]", $reviewsLogins)) {
        $botReviewed = true;
    }

    $intersections = array_intersect($reviewsLogins, $collaboratorsLogins);

    if (count($intersections) > 0) {
        $invokerReviewed = true;
    }

    if ($pullRequestUpdated->assignee == null) {
        $body = array("assignees" => $collaboratorsLogins);
        doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], $body, "POST");
    }

    if (!$botReviewed) {
        $body = array("event" => "APPROVE");
        doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], $body, "POST");
    }

    $autoReview = in_array($pullRequest->Sender, $config->pullRequests->autoReviewSubmitters);

    if (!$invokerReviewed && $autoReview) {
        $bodyMsg = "Automatically approved by " . $metadata["botNameMarkdown"];
        $body = array("event" => "APPROVE", "body" => $bodyMsg);
        doRequestGitHub($metadata["userToken"], $metadata["reviewsUrl"], $body, "POST");
    }

    if (!$invokerReviewed && !$autoReview) {
        $reviewers = $collaboratorsLogins;
        if (in_array($pullRequest->Sender, $reviewers)) {
            $reviewers = array_values(array_diff($reviewers, array($pullRequest->Sender)));
        }
        if (count($reviewers) > 0) {
            $body = array("reviewers" => $reviewers);
            doRequestGitHub($metadata["token"], $metadata["requestReviewUrl"], $body, "POST");
        }
    }

    commentToDependabot($metadata, $pullRequest, $collaboratorsLogins);
    setCheckRunCompleted($metadata, $checkRunId);
}

function setCheckRunInProgress($metadata, $pullRequestUpdated)
{

    $checkRunBody = array(
        "name" => "GStraccini Checks: Pull Request",
        "head_sha" => $pullRequestUpdated->head->sha,
        "status" => "in_progress",
        "output" => array(
            "title" => "Running checks...",
            "summary" => "",
            "text" => ""
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"], $checkRunBody, "POST");
    $result = json_decode($response->body);
    return $result->id;
}

function setCheckRunCompleted($metadata, $checkRunId)
{
    $checkRunBody = array(
        "name" => "GStraccini Checks: Pull Request",
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "success",
        "output" => array(
            "title" => "Checks completed ✅",
            "summary" => "GStraccini checked this PR successfully!",
            "text" => "No issues found."
        )
    );

    doRequestGitHub($metadata["token"], $metadata["checkRunUrl"] . "/" . $checkRunId, $checkRunBody, "PATCH");
}

function commentToDependabot($metadata, $pullRequest, $collaboratorsLogins)
{
    if ($pullRequest->Sender != "dependabot[bot]") {
        return;
    }

    $commentsRequest = doRequestGitHub($metadata["token"], $metadata["commentsUrl"], null, "GET");
    $comments = json_decode($commentsRequest->body);

    $found = false;

    foreach ($comments as $comment) {
        if (
            stripos($comment->body, $metadata["squashAndMergeComment"]) !== false &&
            in_array($comment->user->login, $collaboratorsLogins)
        ) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        $comment = array("body" => $metadata["squashAndMergeComment"]);
        doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");
    }
}

function removeIssueWipLabel($metadata, $pullRequest)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0) {
        return;
    }

    $issueNumber = $referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes[0]->number;
    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

    $labels = array_column(json_decode($issueResponse->body)->labels, "name");
    if (in_array("WIP", $labels)) {
        doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber . "/labels/WIP", null, "DELETE");
    }
}

function getReviewsLogins($metadata)
{
    $reviewsResponse = doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], null, "GET");
    $reviews = json_decode($reviewsResponse->body);
    return array_map(function ($review) {
        return $review->user->login;
    }, $reviews);
}

function getReferencedIssue($metadata, $pullRequest)
{
    $referencedIssueQuery = array(
        "query" => "query {
        repository(owner: \"" . $pullRequest->RepositoryOwner . "\", name: \"" . $pullRequest->RepositoryName . "\") {
          pullRequest(number: " . $pullRequest->Number . ") {
            closingIssuesReferences(first: 1) {
              nodes {
                  number
              }
            }
          }
        }
      }"
    );

    $referencedIssueResponse = doRequestGitHub($metadata["token"], "graphql", $referencedIssueQuery, "POST");
    return json_decode($referencedIssueResponse->body);
}

function addLabels($metadata, $pullRequest)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0) {
        return;
    }

    $issueNumber = $referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes[0]->number;
    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

    $labels = array_column(json_decode($issueResponse->body)->labels, "name");

    $position = array_search("WIP", $labels);

    if ($position !== false) {
        unset($labels[$position]);
    } else {
        $body = array("labels" => array("WIP"));
        doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber . "/labels", $body, "POST");
    }

    $body = array("labels" => $labels);
    doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $pullRequest->Number . "/labels", $body, "POST");
}

function enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config)
{
    if (
        $pullRequestUpdated->auto_merge == null &&
        in_array($pullRequest->Sender, $config->pullRequests->autoMergeSubmitters)
    ) {
        $body = array(
            "query" => "mutation MyMutation {
            enablePullRequestAutoMerge(input: {pullRequestId: \"" . $pullRequest->NodeId . "\", mergeMethod: SQUASH}) {
                clientMutationId
                 }
        }"
        );
        doRequestGitHub($metadata["userToken"], "graphql", $body, "POST");
    }

    if ($pullRequestUpdated->mergeable_state == "clean" && $pullRequestUpdated->mergeable) {
        echo "Pull request " . $pullRequestUpdated->number . " of " .
            $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . " is mergeable\n";
        //     $body = array("merge_method" => "squash", "commit_title" => $pullRequest->Title);
        //     requestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/merge", $body);
    }
}

function updateBranch($metadata, $pullRequestUpdated)
{
    if ($pullRequestUpdated->mergeable_state == "behind") {
        $url = $metadata["pullRequestUrl"] . "/update-branch";
        $body = array("expected_head_sha" => $pullRequestUpdated->head->sha);
        doRequestGitHub($metadata["token"], $url, $body, "PUT");
    }
}

function main()
{
    $pullRequests = readTable("github_pull_requests");
    foreach ($pullRequests as $pullRequest) {
        handlePullRequest($pullRequest);
        updateTable("github_pull_requests", $pullRequest->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoPullRequests);
$healthCheck->start();
main();
$healthCheck->end();
