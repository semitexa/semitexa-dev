<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Structure;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ScheduledJobDeclaration;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ScheduledJobPlacement;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;
use Symfony\Component\Console\Application;

/**
 * idea-application-job-folder: a scheduled job lives under Application/Job/.
 * A job that already exists elsewhere is a warning (its class name is stored
 * in schedule rows, so it cannot simply move); a job file the verified change
 * ADDS elsewhere fails ai:verify and is told its target path.
 */
final class ScheduledJobPlacementTest extends TestCase
{
    private const JOB = <<<'PHP'
        <?php

        namespace App\Modules\Hello\Application\Service\Job;

        use Semitexa\Core\Attribute\AsService;
        use Semitexa\Scheduler\Attribute\AsScheduledJob;
        use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;

        #[AsService]
        #[AsScheduledJob(key: 'hello.purge', cronExpression: '0 3 * * *')]
        final class PurgeJob implements ScheduledJobInterface
        {
        }
        PHP;

    private const IN_SERVICE = 'src/modules/Hello/src/Application/Service/Job/PurgeJob.php';

    private string $root;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-job-placement-' . uniqid();
        mkdir($this->root, 0755, true);
        $this->projectRoot = dirname(__DIR__, 7);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_an_existing_job_under_service_is_a_warning_not_an_error(): void
    {
        $this->write(self::IN_SERVICE, self::JOB);

        $violations = $this->validate();

        $this->assertCount(1, $violations);
        $this->assertSame(ModuleStructureViolation::CODE_JOB_WRONG_LOCATION, $violations[0]->code);
        $this->assertSame(ModuleStructureViolation::SEVERITY_WARNING, $violations[0]->severity);
        $this->assertFalse($violations[0]->isError());
        $this->assertSame(self::IN_SERVICE, $violations[0]->path);
        $this->assertSame('src/modules/Hello/src/Application/Job/<Feature>/PurgeJob.php', $violations[0]->expected);
        $this->assertStringContainsString('schedule rows', $violations[0]->suggestedFix);
        $this->assertNotSame('', $violations[0]->rationale());
    }

    public function test_a_job_the_change_adds_under_service_is_an_error_naming_the_target(): void
    {
        $this->write(self::IN_SERVICE, self::JOB);

        $violations = $this->validate([self::IN_SERVICE]);

        $this->assertCount(1, $violations);
        $this->assertSame(ModuleStructureViolation::SEVERITY_ERROR, $violations[0]->severity);
        $this->assertStringContainsString('src/modules/Hello/src/Application/Job/<Feature>/PurgeJob.php', $violations[0]->message);
    }

    public function test_a_job_under_application_job_feature_passes_even_when_new(): void
    {
        $path = 'src/modules/Hello/src/Application/Job/Cleanup/PurgeJob.php';
        $this->write($path, self::JOB);

        $this->assertSame([], $this->validate([$path]));
    }

    public function test_a_service_that_is_no_job_passes_even_when_it_only_mentions_one(): void
    {
        // A docblock and a string that show the attribute, a class that calls
        // a job, and a *Job.php basename that is no scheduled job.
        $this->write('src/modules/Hello/src/Application/Service/Job/ReportJob.php', <<<'PHP'
            <?php

            namespace App\Modules\Hello\Application\Service\Job;

            use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;

            /**
             * #[AsScheduledJob(key: 'x', cronExpression: '* * * * *')]
             * final class X implements ScheduledJobInterface
             */
            final class ReportJob
            {
                public const EXAMPLE = <<<'CODE'
            #[AsScheduledJob(key: 'x', cronExpression: '* * * * *')]
            final class X implements ScheduledJobInterface {}
            CODE;

                public function run(ScheduledJobInterface $job): void
                {
                }
            }
            PHP);

        $this->assertSame([], $this->validate(['src/modules/Hello/src/Application/Service/Job/ReportJob.php']));
    }

    public function test_either_mark_alone_makes_a_job(): void
    {
        $this->assertTrue(ScheduledJobDeclaration::in("<?php\n#[AsService, \\Semitexa\\Scheduler\\Attribute\\AsScheduledJob(key: 'a')]\nfinal class A {}\n"));
        $this->assertTrue(ScheduledJobDeclaration::in("<?php\nfinal class A extends B implements C,\n    ScheduledJobInterface\n{\n}\n"));
        $this->assertFalse(ScheduledJobDeclaration::in("<?php\ninterface ScheduledJobInterface {}\n"));
        $this->assertFalse(ScheduledJobDeclaration::in("<?php\n#[Attribute]\nfinal class AsScheduledJob {}\n"));
    }

    public function test_a_name_in_an_attribute_argument_is_no_declaration(): void
    {
        // Review dev#299: #[Handles(AsScheduledJob::class)] names the attribute as an argument.
        $this->assertFalse(ScheduledJobDeclaration::in("<?php\n#[Handles(AsScheduledJob::class)]\nfinal class A {}\n"));
        $this->assertFalse(ScheduledJobDeclaration::in("<?php\n#[Tagged(of: [\\Semitexa\\Scheduler\\Attribute\\AsScheduledJob::class])]\nfinal class A {}\n"));
        $this->assertTrue(ScheduledJobDeclaration::in("<?php\n#[Handles(Other::class), AsScheduledJob(key: 'a')]\nfinal class A {}\n"));
    }

    public function test_an_aliased_import_is_still_a_job(): void
    {
        // Review dev#299: PHP resolves the alias, so the check does too.
        $this->assertTrue(ScheduledJobDeclaration::in("<?php\nuse Semitexa\\Scheduler\\Attribute\\AsScheduledJob as Scheduled;\n#[Scheduled(key: 'a')]\nfinal class A {}\n"));
        $this->assertTrue(ScheduledJobDeclaration::in("<?php\nuse Semitexa\\Scheduler\\Attribute\\{AsScheduledJob as Scheduled};\n#[Scheduled(key: 'a')]\nfinal class A {}\n"));
        $this->assertTrue(ScheduledJobDeclaration::in("<?php\nuse Semitexa\\Scheduler\\Domain\\Contract\\ScheduledJobInterface as Job;\nfinal class A implements Job {}\n"));
        $this->assertFalse(ScheduledJobDeclaration::in("<?php\nuse Semitexa\\Scheduler\\Attribute\\AsScheduledJob as Scheduled;\n#[Other(key: 'a')]\nfinal class A {}\n"));
    }

    public function test_a_file_that_cannot_be_read_is_reported_not_passed(): void
    {
        // Review dev#299. A link to nothing: unreadable for every user, root included.
        $path = 'src/modules/Hello/src/Application/Service/Gone.php';
        mkdir(dirname($this->root . '/' . $path), 0755, true);
        symlink($this->root . '/nowhere.php', $this->root . '/' . $path);

        $violations = $this->validate();

        $this->assertCount(1, $violations);
        $this->assertTrue($violations[0]->isError());
        $this->assertSame($path, $violations[0]->path);
        $this->assertStringContainsString('cannot be read', $violations[0]->message);
    }

    public function test_an_alias_is_not_matched_against_a_qualified_name(): void
    {
        // Review dev#299 round 2: PHP applies no import to a qualified name.
        $use = "<?php\nuse Semitexa\\Scheduler\\Domain\\Contract\\ScheduledJobInterface as Job;\nuse Semitexa\\Scheduler\\Attribute\\AsScheduledJob as Scheduled;\n";
        $this->assertFalse(ScheduledJobDeclaration::in($use . "final class A implements \\Vendor\\Queue\\Job {}\n"));
        $this->assertFalse(ScheduledJobDeclaration::in($use . "#[\\Vendor\\Cron\\Scheduled]\nfinal class A implements Queue\\Job {}\n"));
        $this->assertTrue(ScheduledJobDeclaration::in($use . "final class A implements Job {}\n"));
        $this->assertTrue(ScheduledJobDeclaration::in("<?php\nfinal class A implements \\Semitexa\\Scheduler\\Domain\\Contract\\ScheduledJobInterface {}\n"));
    }

    public function test_a_link_to_no_regular_file_is_reported_and_never_read(): void
    {
        // Review dev#299 round 2: reading /dev/zero never ends.
        $path = 'src/modules/Hello/src/Application/Service/Endless.php';
        mkdir(dirname($this->root . '/' . $path), 0755, true);
        symlink('/dev/zero', $this->root . '/' . $path);

        $violations = $this->validate();

        $this->assertCount(1, $violations);
        $this->assertTrue($violations[0]->isError());
        $this->assertSame($path, $violations[0]->path);
        $this->assertStringContainsString('cannot be read', $violations[0]->message);
    }

    public function test_verify_passes_with_a_warning_on_an_existing_job_and_fails_on_a_new_one(): void
    {
        $this->write(self::IN_SERVICE, self::JOB);

        // The job already existed; the change touched another file of the module.
        $existing = $this->verify([
            new ChangedFile('src/modules/Hello/src/Application/Service/Other.php', ChangedFile::KIND_SERVICE, ChangedFile::STATUS_MODIFIED),
        ]);
        $this->assertSame(VerificationResult::STATUS_PASS, $existing->status, $existing->signal);
        $this->assertSame([], $existing->diagnostics);
        $this->assertCount(1, $existing->warnings);
        $this->assertSame('warning', $existing->warnings[0]['severity']);
        $this->assertStringContainsString('1 warning(s)', $existing->signal);

        // The same file, modified: still the existing job.
        $modified = $this->verify([new ChangedFile(self::IN_SERVICE, ChangedFile::KIND_SERVICE, ChangedFile::STATUS_MODIFIED)]);
        $this->assertSame(VerificationResult::STATUS_PASS, $modified->status, $modified->signal);

        // The change adds it: verify fails and names where it goes.
        $new = $this->verify([new ChangedFile(self::IN_SERVICE, ChangedFile::KIND_SERVICE, ChangedFile::STATUS_ADDED)]);
        $this->assertSame(VerificationResult::STATUS_FAIL, $new->status, $new->signal);
        $this->assertSame(1, $new->exitCode);
        $this->assertSame([], $new->warnings);
        $this->assertCount(1, $new->diagnostics);
        $this->assertSame(ModuleStructureViolation::CODE_JOB_WRONG_LOCATION, $new->diagnostics[0]['code']);
        $this->assertSame('src/modules/Hello/src/Application/Job/<Feature>/PurgeJob.php', $new->diagnostics[0]['expected']);
        $this->assertStringContainsString(ModuleStructureViolation::CODE_JOB_WRONG_LOCATION, $new->signal);
    }

    /**
     * @param list<string> $added
     *
     * @return list<ModuleStructureViolation>
     */
    private function validate(array $added = []): array
    {
        return (new ScheduledJobPlacement($this->root))->violations(
            new DetectedModule(name: 'Hello', relativePath: 'src/modules/Hello', kind: DetectedModule::KIND_APPLICATION),
            $added,
        );
    }

    /**
     * The planner's module_structure target for the change, run by the executor.
     *
     * @param list<ChangedFile> $changed
     */
    private function verify(array $changed): VerificationResult
    {
        $plan = (new VerificationPlanner($this->root))->plan($changed, VerificationPlan::SCOPE_MINIMAL);
        $targets = array_values(array_filter(
            $plan->targets,
            static fn (VerificationTarget $t): bool => $t->type === VerificationTarget::TYPE_MODULE_STRUCTURE,
        ));
        $this->assertCount(1, $targets);

        $executor = new VerificationExecutor(
            new Application(),
            $this->root,
            specLoader: new ModuleStructureSpecLoader($this->projectRoot),
        );
        $results = $executor->execute(new VerificationPlan(
            scope: VerificationPlan::SCOPE_MINIMAL,
            effectiveScope: VerificationPlan::SCOPE_MINIMAL,
            changedFiles: $changed,
            targets: $targets,
        ));

        return $results[0];
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }
}
