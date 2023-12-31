<?php

require_once "vendor/autoload.php";
require_once "config/config.php";

function handlePullRequest($pullRequest)
{
    global $gitHubUserToken;
    $config = loadConfig();

    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);

    $metadata = array(
        "token" => $token,
        "squashAndMergeComment" => "@dependabot squash and merge",
        "commentsUrl" => "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->Number . "/comments",
        "pullRequestUrl" => "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/pulls/" . $pullRequest->Number,
        "reviewsUrl" => "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/pulls/" . $pullRequest->Number . "/reviews",
        "assigneesUrl" => "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->Number . "/assignees",
        "collaboratorsUrl" => "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/collaborators",
        "requestReviewUrl" => "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->Number . "/requested_reviewers",
    );

    $pullRequestResponse = requestGitHub($metadata["token"], $metadata["pullRequestUrl"]);
    $pullRequestUpdated = json_decode($pullRequestResponse["body"]);

    if ($pullRequestUpdated->state != "open") {
        return;
    }

    $reviewsResponse = requestGitHub($metadata["token"], $metadata["reviewsUrl"]);
    $reviews = json_decode($reviewsResponse["body"]);
    $reviewsLogins = array_map(function ($review) {
        return $review->user->login;
    }, $reviews);

    $collaboratorsResponse = requestGitHub($metadata["token"], $metadata["collaboratorsUrl"]);
    $collaborators = json_decode($collaboratorsResponse["body"]);
    $collaboratorsLogins = array_map(function ($collaborator) {
        return $collaborator->login;
    }, $collaborators);

    $botReviewed = false;
    $invokerReviewed = false;

    if (in_array($config->botName . "[bot]", $reviewsLogins)) {
        $botReviewed = true;
    }

    $intersections = array_intersect($reviewsLogins, $collaboratorsLogins);

    if (count($intersections) > 0) {
        $invokerReviewed = true;
    }

    if ($pullRequestUpdated->assignee == null) {
        $body = array("assignees" => $collaboratorsLogins);
        requestGitHub($metadata["token"], $metadata["assigneesUrl"], $body);
    }

    if (!$botReviewed) {
        $body = array("event" => "APPROVE");
        requestGitHub($metadata["token"], $metadata["reviewsUrl"], $body);
    }

    $autoReview = in_array($pullRequest->Sender, $config->pullRequests->autoReviewSubmitters);
    
    if (!$invokerReviewed && $autoReview) {
        $body = array(
            "event" => "APPROVE",
            "body" => "Automatically approved by [" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")"
        );
        requestGitHub($gitHubUserToken, $metadata["reviewsUrl"], $body);
    }

    if(!$invokerReviewed && !$autoReview){
        $body = array("reviewers" => $collaboratorsLogins);
        requestGitHub($metadata["token"], $metadata["requestReviewUrl"], $body);
    }

    if ($pullRequestUpdated->auto_merge == null && in_array($pullRequest->Sender, $config->pullRequests->autoMergeSubmitters)) {
        $body = array(
            "query" => "mutation MyMutation {
            enablePullRequestAutoMerge(input: {pullRequestId: \"" . $pullRequest->NodeId . "\", mergeMethod: SQUASH}) {
                clientMutationId
                 }
        }"
        );
        requestGitHub($gitHubUserToken, "graphql", $body);
    }

    if ($pullRequest->Sender == "dependabot[bot]") {
        $commentsRequest = requestGitHub($metadata["token"], $metadata["commentsUrl"]);
        $comments = json_decode($commentsRequest["body"]);

        $found = false;

        foreach ($comments as $comment) {
            if (stripos($comment->body, $metadata["squashAndMergeComment"]) !== false && in_array($comment->user->login, $collaboratorsLogins)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $comment = array("body" => $metadata["squashAndMergeComment"]);
            requestGitHub($gitHubUserToken, $metadata["commentsUrl"], $comment);
        }
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

sendHealthCheck($healthChecksIoPullRequests, "/start");
main();
sendHealthCheck($healthChecksIoPullRequests);
