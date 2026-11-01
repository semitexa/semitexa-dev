<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiDecisionCommand;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The owner's decisions the way an agent uses them: look up before asking,
 * record after, spend a one-time approval with the act it allowed.
 */
final class AiDecisionCommandTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-decision-' . uniqid('', true);
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
        self::assertSame($this->root, ProjectRoot::get(), 'decisions must never be written into the real project');
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_one_time_approval_is_found_spent_and_then_asked_for_again(): void
    {
        [$status, $out] = $this->decision('record', ['--words' => 'Так, випускай реліз після злиття PR', '--means' => 'one stable release', '--applies-to' => 'Release', '--scope' => 'once']);
        self::assertSame(0, $status, json_encode($out) ?: '');
        $id = $out['decision']['id'] ?? null;
        self::assertIsString($out['decision']['decided_at']);
        self::assertSame('d-' . substr(str_replace('-', '', $out['decision']['decided_at']), 0, 8) . '-release', $id);
        self::assertSame(['release', 'once', 'owner', 'active'], [$out['decision']['applies_to'], $out['decision']['scope'], $out['decision']['by'], $out['decision']['status']]);

        self::assertSame([$id], $this->ids(['--applies-to' => 'release']));
        self::assertSame([], $this->ids(['--applies-to' => 'dependency']));

        [$status, $out] = $this->decision('consume', ['--id' => $id, '--act' => 'released 2026.11.01.0448']);
        self::assertSame([0, 'consumed', 'released 2026.11.01.0448'], [$status, $out['decision']['status'], $out['decision']['consumed_by']]);

        self::assertSame([], $this->ids(['--applies-to' => 'release']), 'spent: the next release is asked for again');
        self::assertSame([$id], $this->ids(['--applies-to' => 'release', '--all' => true]));
        self::assertSame([1, "decision '{$id}' is consumed: it allows nothing more"], $this->error('consume', ['--id' => $id, '--act' => 'again']));
    }

    #[Test]
    public function a_standing_decision_holds_until_revoked(): void
    {
        $this->decision('record', ['--id' => 'data-as-patches', '--words' => 'дані мігрувати лише патчами', '--means' => 'data migrations are #[AsDataPatch]', '--applies-to' => 'data', '--scope' => 'standing']);

        self::assertSame([1, "decision 'data-as-patches' is standing: it is revoked, not consumed"], $this->error('consume', ['--id' => 'data-as-patches', '--act' => 'x']));
        [$status, $out] = $this->decision('revoke', ['--id' => 'data-as-patches']);
        self::assertSame([0, 'revoked'], [$status, $out['decision']['status']]);
        self::assertSame([], $this->ids([]));
        self::assertSame([1, "decision 'data-as-patches' is revoked: nothing left to revoke"], $this->error('revoke', ['--id' => 'data-as-patches']));
    }

    #[Test]
    public function ids_are_unique_and_a_bad_record_writes_nothing(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            [, $out] = $this->decision('record', ['--words' => 'ok', '--means' => 'm', '--applies-to' => 'new dependency', '--scope' => 'standing']);
            $ids[] = $out['decision']['id'] ?? null;
        }
        self::assertIsString($ids[0]);
        $base = $ids[0];
        self::assertMatchesRegularExpression('/^d-\d{8}-new-dependency$/', $base);
        self::assertSame([$base, $base . '-2', $base . '-3'], $ids);

        $refused = [
            [['--means' => 'm', '--applies-to' => 'a', '--scope' => 'once'], 'record needs --words (the owner\'s), --means, --applies-to and --scope=standing|once'],
            [['--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'forever'], 'record needs --words (the owner\'s), --means, --applies-to and --scope=standing|once'],
            [['--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'once', '--expires' => '2020-01-01'], '--expires 2020-01-01 is already past'],
            [['--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'once', '--expires' => 'someday'], '--expires "someday" is not a date'],
            [['--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'once', '--expires' => '2099-02-30'], '--expires "2099-02-30" is not a date'],
            [['--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'once', '--expires' => '+1 week'], '--expires "+1 week" is not a date'],
            [['--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'once', '--expires' => '2099-01-01T00:00:00'], '--expires "2099-01-01T00:00:00" is not a date'],
            [['--id' => $base, '--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'once'], "decision '{$base}' already exists"],
            [['--id' => '../x', '--words' => 'w', '--means' => 'm', '--applies-to' => 'a', '--scope' => 'once'], "invalid decision id '../x': must match /^[a-z0-9][a-z0-9_-]{0,63}$/"],
        ];
        foreach ($refused as [$options, $message]) {
            self::assertSame([1, $message], $this->error('record', $options), json_encode($options) ?: '');
        }
        self::assertCount(3, (new DecisionStore())->list());
    }

    #[Test]
    public function an_expiring_decision_says_when_and_a_broken_file_fails_the_listing(): void
    {
        // A bare date holds through that day; today is not "already past".
        [, $out] = $this->decision('record', ['--words' => 'w', '--means' => 'm', '--applies-to' => 'docs', '--scope' => 'standing', '--expires' => '2099-01-01']);
        self::assertSame('2099-01-02T00:00:00+00:00', $out['decision']['expires_at']);
        self::assertSame(0, $this->decision('record', ['--words' => 'w', '--means' => 'm', '--applies-to' => 'docs', '--scope' => 'standing', '--expires' => gmdate('Y-m-d')])[0]);

        $dir = $this->root . '/var/ai-work/decisions';
        file_put_contents($dir . '/broken.json', '{"id": "broken"}');
        self::assertSame([1, 'var/ai-work/decisions/broken.json: not a semitexa.ai-decision/v1 decision'], $this->error('list', []));

        $valid = (new \Semitexa\Dev\Application\Service\Ai\Decision\Decision('soon', 'w', 'm', 'docs', \Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope::ONCE, '2026-11-01T10:00:00+00:00'))->toFileArray();
        file_put_contents($dir . '/broken.json', (string) json_encode(['id' => 'broken', 'expires_at' => 'soon'] + $valid));
        self::assertSame([1, 'var/ai-work/decisions/broken.json: decision expires_at must be an ISO-8601 time with its offset, got "soon"'], $this->error('list', []));

        file_put_contents($dir . '/broken.json', (string) json_encode(['id' => 'other'] + $valid));
        self::assertSame([1, "var/ai-work/decisions/broken.json: holds decision 'other'; the file is named after its id"], $this->error('list', []));
    }

    #[Test]
    public function an_expired_or_spent_decision_allows_nothing_and_cannot_be_revoked(): void
    {
        $store = new DecisionStore();
        $once = static fn (string $id, ?string $expires): \Semitexa\Dev\Application\Service\Ai\Decision\Decision => new \Semitexa\Dev\Application\Service\Ai\Decision\Decision($id, 'w', 'm', 'release', \Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope::ONCE, '2026-01-01T00:00:00+00:00', expiresAt: $expires);
        mkdir($this->root . '/var/ai-work/decisions', 0755, true);
        $store->save($once('old', '2026-01-02T00:00:00+00:00'));
        $store->save($once('spent', null)->consumed('2026-01-01T01:00:00+00:00', 'a release'));
        $store->save($once('taken-back', null)->revokedNow());

        self::assertSame([], $this->ids([]));
        self::assertSame([1, "decision 'old' is expired: it allows nothing more"], $this->error('consume', ['--id' => 'old', '--act' => 'x']));
        self::assertSame([1, "decision 'taken-back' is revoked: it allows nothing more"], $this->error('consume', ['--id' => 'taken-back', '--act' => 'x']));
        self::assertSame([1, "decision 'spent' is consumed: nothing left to revoke"], $this->error('revoke', ['--id' => 'spent']));
        self::assertSame([1, "decision 'old' is expired: nothing left to revoke"], $this->error('revoke', ['--id' => 'old']));
        self::assertSame('consumed', $store->get('spent')->status(new \DateTimeImmutable())->value, 'a spent approval stays spent on record');
    }

    #[Test]
    public function a_listing_sorts_by_instant_and_filters_whatever_the_case(): void
    {
        $store = new DecisionStore();
        mkdir($this->root . '/var/ai-work/decisions', 0755, true);
        $d = static fn (string $id, string $at, string $about): \Semitexa\Dev\Application\Service\Ai\Decision\Decision => new \Semitexa\Dev\Application\Service\Ai\Decision\Decision($id, 'w', 'm', $about, \Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope::STANDING, $at);
        // 10:00+02:00 is 08:00 UTC: earlier than 09:00 UTC, though it sorts later as text.
        $store->save($d('later', '2026-11-01T09:00:00+00:00', 'release'));
        $store->save($d('earlier', '2026-11-01T10:00:00+02:00', 'Release'));

        self::assertSame(['earlier', 'later'], $this->ids(['--applies-to' => 'RELEASE']));
    }

    #[Test]
    public function a_decision_that_could_not_be_read_back_is_not_written(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DecisionStore())->save(new \Semitexa\Dev\Application\Service\Ai\Decision\Decision('bad', 'w', 'm', 'release', \Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope::STANDING, 'yesterday'));
    }

    #[Test]
    public function two_agents_spending_one_approval_at_once_spend_it_once(): void
    {
        $this->decision('record', ['--id' => 'one-release', '--words' => 'w', '--means' => 'm', '--applies-to' => 'release', '--scope' => 'once']);
        $store = new DecisionStore();
        // The other agent holds the lock and has read the approval as still
        // active; this one starts consuming meanwhile. Unlocked, it reads
        // "active" too and spends it a second time (exit 0); locked, it waits,
        // then finds it spent.
        $outcome = $store->locked(function () use ($store): string {
            $pid = pcntl_fork();
            if ($pid === 0) {
                [$status] = $this->decision('consume', ['--id' => 'one-release', '--act' => 'this agent']);
                exit($status);
            }
            usleep(300_000);
            $store->save($store->get('one-release')->consumed('2026-11-01T10:00:00+00:00', 'the other agent'));

            return (string) $pid;
        });
        pcntl_waitpid((int) $outcome, $status);
        self::assertSame(1, pcntl_wexitstatus($status), 'the waiting consume was refused');
        self::assertSame('the other agent', $store->get('one-release')->consumedBy);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<mixed>
     */
    private function ids(array $options): array
    {
        [$status, $out] = $this->decision('list', $options);
        self::assertSame(0, $status);
        self::assertIsArray($out['decisions']);

        return array_column($out['decisions'], 'id');
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, mixed}
     */
    private function error(string $action, array $options): array
    {
        [$status, $out] = $this->decision($action, $options);

        return [$status, $out['error'] ?? null];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     */
    private function decision(string $action, array $options): array
    {
        $command = new AiDecisionCommand();
        PropertyInjector::inject($command, new ArrayContainer([DecisionStore::class => new DecisionStore()]));
        $tester = new CommandTester($command);
        $status = $tester->execute(['action' => $action] + $options, ['interactive' => false]);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, $tester->getDisplay());

        /** @var array<string, mixed> $decoded */
        return [$status, $decoded];
    }
}
