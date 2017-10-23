<?php


namespace mbaynton\StatGather;


use mbaynton\StatGather\Model\ProjectModel;

class DBService {

  protected $pdo;

  protected $testProjectStmt;

  protected $insertProjectStmt;

  protected $insertPackageStmt;

  protected $insertDependencyStmt;

  public function __construct() {
    $this->pdo = new \PDO('sqlite:database.sqlite');
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    $this->testProjectStmt = $this->pdo->prepare(
      'SELECT id FROM project WHERE cms = :cms AND name = :name'
    );

    $this->insertProjectStmt = $this->pdo->prepare(
      'INSERT INTO project (cms, name, num_sites, declares_external_repos) VALUES(:cms, :name, :num_sites, :declares_external_repos)'
    );

    $this->insertPackageStmt = $this->pdo->prepare(
      'INSERT OR IGNORE INTO package (name) VALUES(:name)'
    );

    $this->testPackageStmt = $this->pdo->prepare(
      'SELECT id FROM package WHERE name = :name'
    );

    $this->insertDependencyStmt = $this->pdo->prepare(
      'INSERT OR IGNORE INTO project_package (project_id, package_id, is_direct) VALUES(:project_id, :package_id, :is_direct)'
    );
  }

  /**
   * @param \mbaynton\StatGather\Model\ProjectModel $project
   * @return int|bool
   */
  public function testProjectExists(ProjectModel $project) {
    $this->testProjectStmt->execute([
      ':cms' => $project->cms,
      ':name' => $project->machine_name
    ]);
    $match = $this->testProjectStmt->fetchObject();
    $this->testProjectStmt->closeCursor();
    if ($match !== false) {
      return $match->id;
    } else {
      return FALSE;
    }
  }

  public function getProjectId(ProjectModel $project) {
    try {
      $this->pdo->beginTransaction();
      $id = $this->testProjectExists($project);
      if ($id !== false) {
        $this->pdo->commit();
        return $id;
      } else {
        $this->insertProjectStmt->execute([
          ':cms' => $project->cms,
          ':name' => $project->machine_name,
          ':num_sites' => $project->num_sites,
          ':declares_external_repos' => $project->declares_external_repos,
        ]);
        $id = $this->pdo->lastInsertId();
        $this->pdo->commit();
        return $id;
      }
    } catch (\PDOException $e) {
      $this->pdo->rollBack();
      return false;
    }
  }

  public function recordDependency(ProjectModel $project, $package, $isDirect = false) {
    $projectId = $this->getProjectId($project);

    $this->insertPackageStmt->execute([':name' => $package]);
    $this->testPackageStmt->execute([':name' => $package]);
    $packageId = $this->testPackageStmt->fetchColumn(0);
    $this->testPackageStmt->closeCursor();
    if ($packageId === false) {
      throw new \LogicException();
    }
    $this->insertDependencyStmt->execute([':project_id' => $projectId, ':package_id' => $packageId, ':is_direct' => $isDirect]);
  }

  public function markProjectNotInstallable(ProjectModel $project) {
    $stmt = $this->pdo->prepare('UPDATE project SET not_installable = 1 WHERE name = :name AND cms = :cms');
    $stmt->execute([':name' => $project->machine_name, ':cms' => $project->cms]);
    $stmt->closeCursor();
  }

  /**
   * @return ProjectModel|null
   */
  public function getLastAnalyzedProject() {
    $stmt = $this->pdo->prepare('SELECT * FROM project ORDER BY id DESC LIMIT 1');
    $stmt->execute();
    if (($project = $stmt->fetchObject(ProjectModel::class)) != false) {
      $project->machine_name = $project->name;
      $stmt->closeCursor();
      return $project;
    } else {
      $stmt->closeCursor();
      return null;
    }
  }
}
