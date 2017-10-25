#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['fast-forward', 'filter:']);

$streams = new \cli\Streams();
$dbService = new \mbaynton\StatGather\DBService();
$progressBar = new \cli\progress\Bar('Analyzing projects...', 1);

$service = new \mbaynton\StatGather\DrupalGatherer(
  new \GuzzleHttp\Client(),
  $streams,
  $dbService,
  new \mbaynton\StatGather\ComposerWranglerService()
);

$progressBar->display();

// Read releases.tsv from stdin.
// https://drupal.org/files/releases.tsv
$projects = $service->processReleasesTsv(STDIN);

if (isset($opts['filter'])) {
  if (substr($opts['filter'], 0, 1) === substr($opts['filter'], -1, 1)) {
      $filter = $opts['filter'];
  } else {
      $filter = '|' . $opts['filter'] . '|';
  }
  $projects = array_filter($projects, function($project) use($filter) {
      return preg_match($filter, $project->machine_name);
  });
}

$progressBar->setTotal(count($projects));

$lastProcessedProject = NULL;
if (isset($opts['fast-forward'])) {
  // If we've already processed projects from a previous run, fast forward until
  // we get past it.
  $lastProcessedProject = $dbService->getLastAnalyzedProject();
}


foreach ($projects as $project) {
  if ($lastProcessedProject !== NULL) {
    if ($project->machine_name == $lastProcessedProject->machine_name
      && $project->cms == $lastProcessedProject->cms
    ) {
      $lastProcessedProject = NULL;
    }
    $progressBar->tick();
  }
  else {
    $service->processProjectModel($project);
    $progressBar->tick();
  }
}

$progressBar->finish();
