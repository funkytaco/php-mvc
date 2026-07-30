<?php

declare(strict_types=1);

namespace Test\Nimbus\Database;

use Nimbus\Database\DatabaseEngine;
use PHPUnit\Framework\TestCase;

class DatabaseEngineTest extends TestCase
{
    /**
     * The whole backward-compatibility story rests on this: an app that
     * declares no engine must resolve to exactly what AppManager used to
     * hardcode.
     */
    public function testConfiglessAppResolvesToTheHistoricalPostgresValues(): void
    {
        $engine = DatabaseEngine::fromConfig([]);

        $this->assertSame('postgres', $engine->name);
        $this->assertSame('postgres:14', $engine->image);
        $this->assertSame('demo-postgres', $engine->containerName('demo'));
        $this->assertSame(
            ['POSTGRES_DB' => 'demo_db', 'POSTGRES_USER' => 'demo_user', 'POSTGRES_PASSWORD' => 'secret'],
            $engine->environment('demo_db', 'demo_user', 'secret')
        );
        $this->assertSame('pg_isready -U demo_user -d demo_db', $engine->healthcheckCmd('demo_user', 'demo_db'));
        $this->assertSame(['PG_VERSION', 'postgresql.conf', 'pg_hba.conf'], $engine->dataFiles());
    }

    public function testBareOptInGivesTheGitDefault(): void
    {
        foreach ([null, true, '', 'true', 'yes', '1'] as $spec) {
            $engine = DatabaseEngine::fromSpec($spec);

            $this->assertSame('mariadb', $engine->name);
            $this->assertSame('mariadb:12', $engine->image);
        }
    }

    public function testEngineNamesResolveToPinnedImages(): void
    {
        $this->assertSame('mariadb:12', DatabaseEngine::fromSpec('mariadb')->image);
        $this->assertSame('mysql:8.4', DatabaseEngine::fromSpec('mysql')->image);
        $this->assertSame('postgres:14', DatabaseEngine::fromSpec('postgres')->image);

        // Aliases people actually type
        $this->assertSame('postgres', DatabaseEngine::fromSpec('postgresql')->name);
        $this->assertSame('postgres', DatabaseEngine::fromSpec('pgsql')->name);
        $this->assertSame('mariadb', DatabaseEngine::fromSpec('MariaDB')->name);
    }

    public function testFullImageReferenceInfersItsEngine(): void
    {
        $engine = DatabaseEngine::fromSpec('docker.io/library/mariadb:11.4');

        $this->assertSame('mariadb', $engine->name);
        $this->assertSame('docker.io/library/mariadb:11.4', $engine->image);
    }

    public function testDigestPinnedReferenceIsAccepted(): void
    {
        $ref = 'mariadb@sha256:' . str_repeat('a', 64);

        $this->assertSame('mariadb', DatabaseEngine::fromSpec($ref)->name);
    }

    /**
     * An unpinned image changes what the stack is built from between one run
     * and the next, which defeats having a recorded baseline at all
     * (NIST 800-53 CM-2, CIS/STIG container image policy).
     */
    public function testLatestTagIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/:latest/');

        DatabaseEngine::fromSpec('mariadb:latest');
    }

    public function testUntaggedImageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no version tag/');

        DatabaseEngine::fromSpec('docker.io/library/mariadb');
    }

    public function testUnrecognizableEngineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot tell which database engine/');

        DatabaseEngine::fromSpec('cockroachdb:24.1');
    }

    public function testMysqlFamilyUsesItsOwnVariablesAndDbSuffix(): void
    {
        $engine = DatabaseEngine::fromSpec('mariadb');

        $this->assertSame('blog-db', $engine->containerName('blog'));
        $this->assertSame(
            [
                'MYSQL_DATABASE' => 'blog_db',
                'MYSQL_USER' => 'blog_user',
                'MYSQL_PASSWORD' => 'pw',
                'MYSQL_ROOT_PASSWORD' => 'rootpw',
            ],
            $engine->environment('blog_db', 'blog_user', 'pw', 'rootpw')
        );
        $this->assertTrue($engine->usesRootPassword());
        $this->assertSame(3306, $engine->port());
        $this->assertFalse($engine->isPostgres());
    }

    /**
     * The images refuse to initialize with no root password at all, so an
     * empty one must fall back rather than being emitted blank.
     */
    public function testRootPasswordFallsBackToTheUserPassword(): void
    {
        $environment = DatabaseEngine::fromSpec('mysql')->environment('d', 'u', 'pw');

        $this->assertSame('pw', $environment['MYSQL_ROOT_PASSWORD']);
    }

    public function testPostgresNeverEmitsARootPassword(): void
    {
        $environment = DatabaseEngine::named('postgres')->environment('d', 'u', 'pw', 'rootpw');

        $this->assertArrayNotHasKey('MYSQL_ROOT_PASSWORD', $environment);
        $this->assertCount(3, $environment);
        $this->assertFalse(DatabaseEngine::named('postgres')->usesRootPassword());
    }

    /**
     * Health check commands are emitted as raw YAML list items, so they must
     * carry nothing the hand-rolled emitter would mangle.
     */
    public function testHealthcheckCommandsAreSafeForTheRawYamlEmitter(): void
    {
        foreach (DatabaseEngine::supported() as $name) {
            $command = DatabaseEngine::named($name)->healthcheckCmd('u', 'd');

            $this->assertStringNotContainsString(': ', $command, "$name health check");
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]/', $command, "$name health check");
        }
    }

    /**
     * fromConfig reads config that was already validated at create time, so it
     * must never reject an app it is merely being asked to inspect.
     */
    public function testFromConfigIsPermissiveAboutAlreadyStoredValues(): void
    {
        $engine = DatabaseEngine::fromConfig([
            'database' => ['engine' => 'mariadb', 'image' => 'mariadb:latest'],
        ]);

        $this->assertSame('mariadb:latest', $engine->image);
    }

    public function testFromConfigFallsBackWhenTheEngineIsUnknown(): void
    {
        $this->assertSame('postgres', DatabaseEngine::fromConfig(['database' => ['engine' => 'nope']])->name);
    }
}
