<?php
namespace mbaynton\StatGather;

use Balsama\DrupalOrgProject\Stats;
use cli\Streams;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use mbaynton\StatGather\Model\ProjectModel;
use PHPHtmlParser\Exceptions\CurlException;

class DrupalGatherer {

  /**
   * @var ClientInterface
   */
  protected $guzzle;

  /**
   * @var Streams
   */
  protected $output;

  /**
   * @var \mbaynton\StatGather\DBService
   */
  protected $dbService;

  /**
   * @var \mbaynton\StatGather\ComposerWranglerService
   */
  protected $composerWrangler;

  public function __construct(
    ClientInterface $httpClient,
    Streams $output,
    DBService $dbService,
    ComposerWranglerService $composerWrangler
  ) {
    $this->guzzle = $httpClient;
    $this->output = $output;
    $this->dbService = $dbService;
    $this->composerWrangler = $composerWrangler;
  }

  /**
   * @param $fh
   * File/stream handle to releases.tsv
   * @return ProjectModel[]
   *   A ProjectModel representation of every drupal project.
   */
  public function processReleasesTsv($fh) {
    $results = [];
    while(($line = fgets($fh)) !== FALSE) {
      $fields = explode("\t", $line, 5);
      $project = new ProjectModel();
      list(
        ,
        $project->machine_name,
        $project->version,
        $project->compatible_api
      ) = $fields;

      // If it's the header line, don't include it.
      if ($project->machine_name === 'project_machine_name') {
        continue;
      }

      $project->cms = 'D' . substr($project->compatible_api, 0, 1);

      $key = sprintf("%s/%s", $project->machine_name, $project->compatible_api);
      if (! isset($results[$key])) {
        $results[$key] = $project;
      }
    }

    return array_values($results);
  }

  public function processProjectModel(ProjectModel $project) {
    // Have we processed it already?
    if ($this->dbService->testProjectExists($project) !== false) {
      return;
    }

    // Get usage.
    $retries = 0;
    $project->num_sites = -1;
    while ($retries < 2) {
      $retries++;
      try {
        $domProject = new Stats($project->machine_name, TRUE);
        switch ($project->compatible_api) {
          case '8.x':
            $project->num_sites = $domProject->getCurrentD8Usage();
            break;
          case '7.x':
            $project->num_sites = $domProject->getCurrentD7Usage();
            break;
        }
      } catch (CurlException $e) {
        sleep(5);
        continue;
      }
    }

    // Does it use Composer?
    $composerJsonUrl = sprintf('http://cgit.drupalcode.org/%s/plain/composer.json?h=%s',
      $project->machine_name,
      $project->version
    );
    try {
      $response = $this->guzzle->request('GET', $composerJsonUrl);
    } catch (ClientException $e) {
      if ($e->getResponse() !== null) {
        $response = $e->getResponse();
      } else {
        $this->output->err(
          "Error checking if %s uses composer.json: %s\n",
          $project->machine_name,
          $e->getMessage()
        );
        return;
      }
    }

    if (! in_array($response->getStatusCode(), [200, 404])) {
      $this->output->err(
        "Error checking if %s uses a composer.json: %s\n",
        $project->machine_name,
        $response->getStatusCode() . ' ' . $response->getReasonPhrase()
      );
    } else if ($response->getStatusCode() == 200) {
      // This project uses composer.json, so is of further interest.

      // Parse composer.json to record direct dependencies.
      $composerJson = json_decode($response->getBody());
      if ($composerJson === null) {
        $this->dbService->markProjectNotInstallable($project);
        return;
      }
      $project->declares_external_repos = false;
      if (property_exists($composerJson, 'repositories') && count($composerJson->repositories)) {
        // don't count the official drupal.org repo
        foreach ($composerJson->repositories as $repo) {
          if (
            ! isset($repo->url) || (
              $repo->url != 'https://packages.drupal.org/8'
              && $repo->url != 'https://packages.drupal.org/7'
            )) {
            $project->declares_external_repos = true;
          }
        }
      }

      $projectId = $this->dbService->getProjectId($project);
      if ($projectId === false) {
        $this->output->err(
          "Error recording project %s for %s\n",
          $project->machine_name,
          $project->cms
        );
        return;
      }

      // If the module requires external repositories, don't try to work out
      // what its dependencies are.
      if (! $project->declares_external_repos
        && property_exists($composerJson, 'require')
        && count($composerJson->require)) {
        $requires = $composerJson->require;
        foreach ($requires AS $package => $constraint) {
          $this->dbService->recordDependency($project, $package, true);
        }

        $composerJsonPath = $project->compatible_api == '7.x' ?
          __DIR__ . DIRECTORY_SEPARATOR . '../d7_template/composer.json' :
          __DIR__ . DIRECTORY_SEPARATOR . '../d8_template/composer.json';
        $package = 'drupal/' . $project->machine_name;

        try {
          if ($package === 'drupal/drupal') {
            $indirectDependencies = $this->composerWrangler->findDependenciesUsingCreateProject($package);
          } else {
            $indirectDependencies = $this->composerWrangler->findDependenciesUsingExistingComposerJson(
              $composerJsonPath,
              $package
            );
          }

          foreach ($indirectDependencies as $indirectDependency) {
            $this->dbService->recordDependency($project, $indirectDependency, false);
          }
        } catch (\RuntimeException $e) {
          if ($e->getCode() == 1) {
            // Composer couldn't create an installable set of dependencies.
            // Likely the project's composer.json is broken.
            $this->dbService->markProjectNotInstallable($project);
          } else {
            $this->output->err(
              "Unknown error analyzing %s: %s\n",
              $project->machine_name,
              $e->getMessage()
            );
          }
        }
      }
    } else {
      // Project doesn't use composer, just record it for its usage data.
      $this->dbService->getProjectId($project);
    }
  }
}
