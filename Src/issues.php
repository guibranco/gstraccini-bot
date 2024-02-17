<?php

require_once "vendor/autoload.php";
require_once "config/config.php";

function handleIssue($issue)
{
    global $gitHubUserToken;

    $token = generateInstallationToken($issue->InstallationId, $issue->RepositoryName);

    $metadata = array(
        "token" => $token,
        "issuesUrl" => "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName . "/issues/" . $issue->Number,
    );

    $issueResponse = requestGitHub($metadata["token"], $metadata["issuesUrl"]);
    $issueUpdated = json_decode($issueResponse["body"]);

    echo "Issue " . $issueUpdated->number . " - " . trim($issueUpdated->title) . " is " . $issueUpdated->state . "\n";
}

function main()
{
    $issues = readTable("github_issues");
    foreach ($issues as $issue) {
        handleIssue($issue);
        updateTable("github_issues", $issue->Sequence);
    }
}

sendHealthCheck($healthChecksIoIssues, "/start");
main();
sendHealthCheck($healthChecksIoIssues);
