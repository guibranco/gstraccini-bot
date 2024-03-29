<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\lib\HealthChecks;

function handleComment($comment)
{
    $config = loadConfig();

    if ($comment->CommentSender === $config->botName . "[bot]") {
        return;
    }

    $metadata = array(
        "token" => generateInstallationToken($comment->InstallationId, $comment->RepositoryName),
        "reactionUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/comments/" . $comment->CommentId . "/reactions",
        "pullRequestUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/pulls/" . $comment->PullRequestNumber,
        "commentUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/" . $comment->PullRequestNumber . "/comments"
    );

    $collaboratorUrl = "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/collaborators/" . $comment->CommentSender;
    $collaboratorResponse = doRequestGitHub($metadata["token"], $collaboratorUrl, null, "GET");
    if ($collaboratorResponse->statusCode === 404) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = "I'm sorry @" . $comment->CommentSender . ", I can't do that, you aren't a collaborator. :pleading_face:";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $executedAtLeastOne = false;

    foreach ($config->commands as $command) {
        $commandExpression = "@" . $config->botName . " " . $command->command;
        if (stripos($comment->CommentBody, $commandExpression) !== false) {
            $executedAtLeastOne = true;
            $method = "execute_" . toCamelCase($command->command);
            $method($config, $metadata, $comment);
        }
    }

    if (!$executedAtLeastOne) {
        $body = "I'm sorry @" . $comment->CommentSender . ", I can't do that. :pleading_face:";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
    }
}

function execute_hello($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "heart"), "POST");
    $body = "Hello @" . $comment->CommentSender . "! :wave:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
}

function execute_thankYou($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");
    $body = "You're welcome @" . $comment->CommentSender . "! :pray:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
}

function execute_help($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $helpComment = "That's what I can do :neckbeard::\r\n";
    foreach ($config->commands as $command) {
        $parameters = "";
        $parametersHelp = "";
        $inDevelopment = isset($command->dev) && $command->dev
            ? " :warning: (in development - maybe not working as expected!)"
            : "";
        if (isset($command->parameters)) {
            foreach ($command->parameters as $parameter) {
                $parameters .= " <" . $parameter->parameter . ">";
                $parametersHelp .= "\t- `" . $parameter->parameter . "`: `[" .
                    ($parameter->required ? "required" : "optional") . "]` " .
                    $parameter->description . "\r\n";
            }
        }
        $helpComment .= "- `@" . $config->botName . " " . $command->command . $parameters . "`: ";
        $helpComment .= $command->description . $inDevelopment . "\r\n";
        $helpComment .= $parametersHelp;
    }
    $helpComment .= "\r\n\r\nMultiple commands can be issued at the same time. " .
        "Just respect each command pattern (with bot name prefix + command).\r\n\r\n" .
        "> **Warning**\r\n> \r\n" .
        "> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $helpComment), "POST");
}

function execute_appveyorBuild($config, $metadata, $comment)
{
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequest = json_decode($pullRequestResponse->body);

    if ($pullRequest->state != "open") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = "This pull request is not open anymore! :no_entry:";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    preg_match("/@" . $config->botName . "\sappveyor\sbuild(?:\s(commit|pull request))?/", $comment->CommentBody, $matches);

    $searchSlug = strtolower($comment->RepositoryOwner . "/" . $comment->RepositoryName);

    $projectsResponse = requestAppVeyor("projects");
    $projects = json_decode($projectsResponse->body);
    $projects = array_filter($projects, function ($p) use ($searchSlug) {
        return $searchSlug === strtolower($p->repositoryName);
    });
    $projects = array_values($projects);

    $data = array(
        "accountName" => $projects[0]->accountName,
        "projectSlug" => $projects[0]->slug
    );

    if (count($matches) === 2 && $matches[1] === "commit") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        $data["branch"] = $pullRequest->head->ref;
        $data["commitId"] = $pullRequest->head->sha;
    } elseif (count($matches) === 2 && $matches[1] === "pull request") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        $data["pullRequestId"] = $comment->PullRequestNumber;
    } else {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = "I'm sorry @" . $comment->CommentSender . ", I can't do that, invalid type parameter. :pleading_face:";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $buildResponse = requestAppVeyor("builds", $data);
    $build = json_decode($buildResponse->body);
    $buildId = $build->buildId;
    $version = $build->version;
    $link = "https://ci.appveyor.com/project/" .
        $projects[0]->accountName . "/" . $projects[0]->slug .
        "/builds/" . $buildId;
    $commentBody = "AppVeyor build started! :rocket:\r\n\r\n" .
        "Build ID: [" . $buildId . "](" . $link . ")\r\n" .
        "Version: " . $version . "\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function execute_appveyorRegister($config, $metadata, $comment)
{
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequest = json_decode($pullRequestResponse->body);

    if ($pullRequest->state != "open") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = "This pull request is not open anymore! :no_entry:";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $data = array(
        "repositoryProvider" => "gitHub",
        "repositoryName" => $comment->RepositoryOwner . "/" . $comment->RepositoryName,
    );
    $registerResponse = requestAppVeyor("projects", $data);
    $register = json_decode($registerResponse->body);

    $link = "https://ci.appveyor.com/project/" .
        $register->accountName . "/" . $register->slug;
    $commentBody = "AppVeyor registered! :rocket:\r\n\r\n" .
        "Project ID: [" . $register->projectId . "](" . $link . ")\r\n" .
        "Slug: " . $register->slug . "\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function execute_appveyorReset($config, $metadata, $comment)
{
    $searchSlug = strtolower($comment->RepositoryOwner . "/" . $comment->RepositoryName);

    $projectsResponse = requestAppVeyor("projects");
    $projects = json_decode($projectsResponse->body);
    $projects = array_filter($projects, function ($p) use ($searchSlug) {
        return $searchSlug === strtolower($p->repositoryName);
    });
    $projects = array_values($projects);

    $data = array("nextBuildNumber" => 0);
    $url = "projects/" . $projects[0]->accountName . "/" . $projects[0]->slug . "/settings/build-number";
    requestAppVeyor($url, $data);
    $commentBody = "AppVeyor build reset! :rocket:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function execute_bumpVersion($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $dotNetLink = "https://dotnet.microsoft.com/en-us/platform/support/policy/dotnet-core";
    $body = "Bumping [.NET version](" . $dotNetLink . ") on this branch! :arrow_heading_up:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "bump-version.yml");
}

function execute_csharpier($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = "Running [CSharpier](https://csharpier.com/) on this branch! :wrench:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "csharpier.yml");
}

function execute_fixCsproj($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $body = "Fixing [NuGet packages](https://nuget.org) references in .csproj files! :pill:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "fix-csproj.yml");
}

function execute_prettier($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = "Running [Prettier](https://prettier.io/) on this branch! :wrench:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "prettier.yml");
}

function execute_review($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");

    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);

    $pullRequest = new \stdClass();
    $pullRequest->DeliveryId = $comment->DeliveryIdText;
    $pullRequest->HookId = $comment->HookId;
    $pullRequest->TargetId = $comment->TargetId;
    $pullRequest->TargetType = $comment->TargetType;
    $pullRequest->RepositoryOwner = $comment->RepositoryOwner;
    $pullRequest->RepositoryName = $comment->RepositoryName;
    $pullRequest->Id = $pullRequestUpdated->id;
    $pullRequest->Sender = $comment->PullRequestSender;
    $pullRequest->Number = $comment->PullRequestNumber;
    $pullRequest->NodeId = $comment->PullRequestNodeId;
    $pullRequest->Title = $pullRequestUpdated->title;
    $pullRequest->Ref = $pullRequestUpdated->head->ref;
    $pullRequest->InstallationId = $comment->InstallationId;

    upsertPullRequest($pullRequest);
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Review enabled! :eyes:"), "POST");
}

function execute_track($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Tracking this pull request! :repeat:"), "POST");
    callWorkflow($config, $metadata, $comment, "track.yml");
}

function execute_updateSnapshot($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Updating test snapshots"), "POST");
    callWorkflow($config, $metadata, $comment, "update-test-snapshot.yml");
}

function callWorkflow($config, $metadata, $comment, $workflow)
{
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequest = json_decode($pullRequestResponse->body);

    $tokenBot = generateInstallationToken($config->botRepositoryInstallationId, $config->botRepository);
    $url = "repos/" . $config->botRepository . "/actions/workflows/" . $workflow . "/dispatches";
    $data = array(
        "ref" => "main",
        "inputs" => array(
            "owner" => $comment->RepositoryOwner,
            "repository" => $comment->RepositoryName,
            "branch" => $pullRequest->head->ref,
            "pull_request" => $comment->PullRequestNumber,
            "installationId" => $comment->InstallationId
        )
    );
    doRequestGitHub($tokenBot, $url, $data, "POST");
}

function main()
{
    $comments = readTable("github_pull_requests_comments");
    foreach ($comments as $comment) {
        handleComment($comment);
        updateTable("github_pull_requests_comments", $comment->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoComments);
$healthCheck->start();
main();
$healthCheck->end();
