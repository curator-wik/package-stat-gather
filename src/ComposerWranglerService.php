<?php


namespace mbaynton\StatGather;


class ComposerWranglerService {

  /**
   * Returns the fully resolved set of dependencies for a composer package.
   *
   * @param $composerJson
   *   Path to a composer.json to copy into a temporary empty sandbox directory.
   * @param $package
   *   The package that will be composer require'd to discover dependencies of.
   * @return string[]
   *   The package names of all dependencies (and dependencies' dependencies.)
   */
  public function findDependenciesUsingExistingComposerJson($composerJson, $package) {
    if (! is_readable($composerJson)) {
      throw new \RuntimeException("$composerJson is not a readable file.");
    }

    $sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'packadist_sandbox';
    if (file_exists($sandbox)) {
      $this->delTree($sandbox);
    }

    mkdir($sandbox, 0744);
    copy($composerJson, $sandbox . DIRECTORY_SEPARATOR . 'composer.json');

    $this->requirePackageInProject($sandbox, $package);

    if (! is_readable($sandbox . DIRECTORY_SEPARATOR . 'composer.lock')) {
      throw new \RuntimeException("Composer require $package did not yield a composer.lock", 1);
    }
    $dependents = iterator_to_array($this->readPackagesFromComposerLock($sandbox . DIRECTORY_SEPARATOR . 'composer.lock'));

    $this->delTree($sandbox);

    return $dependents;
  }

  public function delTree($path) {
    rmrdir($path);
  }

  public function requirePackageInProject($projectPath, $package) {
    $projectPath = escapeshellarg($projectPath);
    $package = escapeshellarg($package);

    shell_exec(<<<CMD
cd $projectPath;
composer require --quiet --no-interaction --ignore-platform-reqs --update-no-dev $package
CMD
);
  }

  public function readPackagesFromComposerLock($compserLockFile) {
    $parsedFile = json_decode(file_get_contents($compserLockFile), true);
    if (isset($parsedFile['packages'])) {
      foreach($parsedFile['packages'] as $package) {
        yield $package['name'];
      }
    }
  }
}
