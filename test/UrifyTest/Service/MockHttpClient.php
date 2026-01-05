<?php declare(strict_types=1);

namespace UrifyTest\Service;

use Laminas\Http\Client;
use Laminas\Http\Response;

/**
 * Mock HTTP client that returns stored fixtures instead of making real HTTP requests.
 *
 * This allows tests to run without network access and ensures reproducible results.
 */
class MockHttpClient extends Client
{
    /**
     * @var string Path to fixtures directory.
     */
    protected $fixturesPath;

    /**
     * @var array Map of URIs to fixture filenames.
     */
    protected $uriMap = [
        'https://www.idref.fr/028618661.rdf' => 'idref_028618661.rdf',
        'https://www.idref.fr/028618661.xml' => 'idref_028618661.xml',
        'https://www.idref.fr/028618661.json' => 'idref_028618661.json',
    ];

    /**
     * @var array Custom responses for specific URIs.
     */
    protected $customResponses = [];

    /**
     * Constructor.
     *
     * @param string $fixturesPath Path to fixtures directory.
     */
    public function __construct(string $fixturesPath)
    {
        parent::__construct();
        $this->fixturesPath = $fixturesPath;
    }

    /**
     * Add a custom response for a URI.
     *
     * @param string $uri The URI to match.
     * @param string $content Response content.
     * @param int $statusCode HTTP status code.
     */
    public function addResponse(string $uri, string $content, int $statusCode = 200): void
    {
        $this->customResponses[$uri] = [
            'content' => $content,
            'statusCode' => $statusCode,
        ];
    }

    /**
     * Add a fixture mapping.
     *
     * @param string $uri The URI to match.
     * @param string $fixtureFilename The fixture filename.
     */
    public function addFixture(string $uri, string $fixtureFilename): void
    {
        $this->uriMap[$uri] = $fixtureFilename;
    }

    /**
     * Send HTTP request - returns fixture content instead of making real request.
     *
     * @param \Laminas\Http\Request|null $request
     * @return Response
     */
    public function send($request = null)
    {
        $uri = $this->getUri()->toString();

        // Check for custom response first.
        if (isset($this->customResponses[$uri])) {
            return $this->createResponse(
                $this->customResponses[$uri]['content'],
                $this->customResponses[$uri]['statusCode']
            );
        }

        // Check for fixture mapping.
        if (isset($this->uriMap[$uri])) {
            $fixturePath = $this->fixturesPath . '/' . $this->uriMap[$uri];
            if (file_exists($fixturePath)) {
                $content = file_get_contents($fixturePath);
                return $this->createResponse($content, 200);
            }
        }

        // No fixture found - return 404.
        return $this->createResponse('Not Found', 404);
    }

    /**
     * Create a response object.
     *
     * @param string $content Response body.
     * @param int $statusCode HTTP status code.
     * @return Response
     */
    protected function createResponse(string $content, int $statusCode): Response
    {
        $response = new Response();
        $response->setStatusCode($statusCode);
        $response->setContent($content);

        // Set appropriate content type for RDF/XML.
        if ($statusCode === 200 && strpos($content, '<?xml') === 0) {
            $response->getHeaders()->addHeaderLine('Content-Type', 'application/rdf+xml');
        }

        return $response;
    }

    /**
     * Reset the client state.
     *
     * @return $this
     */
    public function reset()
    {
        parent::reset();
        return $this;
    }
}
