<?php


namespace mbaynton\StatGather\Test\Unit;


use mbaynton\StatGather\ComposerWranglerService;
use PHPUnit\Framework\TestCase;

class ComposerWrangerServiceTest extends TestCase {
  public function sutFactory() {
    return new ComposerWranglerService();
  }

  public function testReadComposerLock() {
    $sut = $this->sutFactory();
    $packages = iterator_to_array($sut->readPackagesFromComposerLock(
      __DIR__ . '/../../composer.lock'
    ));

    $this->assertGreaterThan(
      5,
      count($packages)
    );
  }
}