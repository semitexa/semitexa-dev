<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryEvidenceFileHandler;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryEvidenceHandler;
use Semitexa\Dev\Application\Payload\Request\ObservatoryEvidenceFilePayload;
use Semitexa\Dev\Application\Payload\Request\ObservatoryEvidencePayload;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceData;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceKind;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceStore;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * The Evidence view's endpoints. The store holds screenshots and traces of
 * real data: the production monitor mode — even with its token — gets the
 * panel's usual 404 and nothing is read; in dev, a file is served sandboxed.
 */
final class ObservatoryEvidenceHandlerTest extends TestCase
{
    private const ENV_KEYS = ['APP_ENV', 'SEMITEXA_OBSERVATORY_MODE', 'SEMITEXA_OBSERVATORY_TOKEN'];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];
    private string $root;
    private string|false $cwd;

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
        }
        $this->root = sys_get_temp_dir() . '/semitexa-evidence-panel-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/modules', 0777, true);
        mkdir($this->root . '/var/tmp', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        $this->cwd = getcwd();
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        CurrentRequestStore::clear();
        foreach ($this->envSnapshot as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function dev(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE');
    }

    private function record(string $name, string $contents): string
    {
        file_put_contents($this->root . '/var/tmp/' . $name, $contents);

        return (new EvidenceStore($this->root))->add('var/tmp/' . $name, EvidenceKind::Screenshot, EvidenceData::Real, '', 'cli', 14, false, new \DateTimeImmutable())->id;
    }

    private function list(array $query): ResourceResponse
    {
        $payload = new ObservatoryEvidencePayload();
        foreach ($query as $name => $value) {
            $payload->{'set' . ucfirst($name)}($value);
        }
        $handler = new ObservatoryEvidenceHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());

        return $handler->handle($payload, new ResourceResponse());
    }

    private function file(string $id): ResourceResponse
    {
        $payload = new ObservatoryEvidenceFilePayload();
        $payload->setId($id);
        $handler = new ObservatoryEvidenceFileHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());

        return $handler->handle($payload, new ResourceResponse());
    }

    #[Test]
    public function monitor_mode_with_its_token_gets_a_404_for_the_list_and_the_files(): void
    {
        $id = $this->record('inbox.png', 'pixels');
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        putenv('SEMITEXA_OBSERVATORY_TOKEN=s3cret');
        CurrentRequestStore::set(new Request(
            method: 'GET',
            uri: '/__observatory/evidence',
            headers: ['X-Observatory-Token' => 's3cret'],
            query: [],
            post: [],
            server: ['remote_addr' => '127.0.0.1'],
            cookies: [],
        ));

        self::assertSame([404, 'Not Found'], [$this->list([])->getStatusCode(), $this->list([])->getContent()]);
        self::assertSame([404, 'Not Found'], [$this->file($id)->getStatusCode(), $this->file($id)->getContent()]);
    }

    #[Test]
    public function dev_gets_one_page_with_its_counts(): void
    {
        $this->dev();
        $this->record('a.png', 'a');
        $this->record('b.png', 'bb');

        $response = $this->list(['per' => '10', 'sort' => 'size', 'dir' => 'asc']);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaders()['Cache-Control'] ?? null);
        self::assertSame(['a.png', 'b.png'], array_column($body['items'], 'file'));
        self::assertSame([2, 1, 1, 10, 2], [$body['total'], $body['page'], $body['pages'], $body['per'], $body['stored']]);
        self::assertSame(['screenshot' => 2], $body['kinds']);
        self::assertStringStartsWith('var/evidence/ev-', $body['items'][0]['path']);
    }

    #[Test]
    public function a_query_nobody_can_mean_is_a_400_that_says_why(): void
    {
        $this->dev();

        $response = $this->list(['from' => '2026-13-01']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'bad-query', 'message' => 'from must be a day as YYYY-MM-DD'], json_decode((string) $response->getContent(), true));
    }

    #[Test]
    public function a_file_is_served_sandboxed_and_never_sniffed(): void
    {
        $this->dev();
        $id = $this->record('shot.png', "\x89PNG");

        $response = $this->file($id);
        $headers = $response->getHeaders();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("\x89PNG", $response->getContent());
        self::assertSame('image/png', $headers['Content-Type'] ?? null);
        self::assertSame('sandbox', $headers['Content-Security-Policy'] ?? null);
        self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
    }

    #[Test]
    public function an_html_export_runs_only_inside_its_sandbox_and_svg_is_text(): void
    {
        $this->dev();
        $html = $this->file($this->record('graph.html', '<script>1</script>'))->getHeaders();
        $svg = $this->file($this->record('logo.svg', '<svg onload="x()"/>'))->getHeaders();
        $odd = $this->file($this->record('bundle.zip', 'PK'))->getHeaders();

        self::assertSame(['text/html; charset=utf-8', 'sandbox allow-scripts'], [$html['Content-Type'] ?? null, $html['Content-Security-Policy'] ?? null]);
        self::assertSame(['text/plain; charset=utf-8', 'sandbox'], [$svg['Content-Type'] ?? null, $svg['Content-Security-Policy'] ?? null]);
        self::assertSame('application/octet-stream', $odd['Content-Type'] ?? null);
        self::assertStringStartsWith('attachment;', $odd['Content-Disposition'] ?? '');
    }

    #[Test]
    public function a_file_that_is_a_link_is_not_followed(): void
    {
        $this->dev();
        $id = $this->record('shot.png', "\x89PNG");
        file_put_contents($this->root . '/.env', 'DB_PASSWORD=secret');
        $file = (new EvidenceStore($this->root))->fileOf((new EvidenceStore($this->root))->find($id) ?? self::fail('recorded'));
        unlink($file);
        symlink($this->root . '/.env', $file);

        $response = $this->file($id);

        self::assertSame([404, 'Not Found'], [$response->getStatusCode(), $response->getContent()]);
    }

    #[Test]
    public function an_id_that_is_not_one_reads_nothing(): void
    {
        $this->dev();
        $this->record('a.png', 'a');

        foreach (['', '../../etc/passwd', 'ev-20261002-120000-abcdef', 'ev-20261002-120000-abcdef/../../x'] as $id) {
            self::assertSame(404, $this->file($id)->getStatusCode(), $id);
        }
    }
}
