<?php
declare(strict_types=1);
namespace App\Http;

use App\Config\Database;
use App\Security\Authenticator;
use App\Tickets\TicketReader;
use App\Tickets\TicketSubmission;
use App\Tickets\IntakeCatalog;
use PDO;
use Throwable;

final class Application
{
    public function __construct(private ?PDO $db = null) {}

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(16));
        try {
            $router = new Router('/api/v1');
            $router->get('/health/live', static fn () => Response::json(['service' => 'jsc-ticketing', 'alive' => true]));
            $router->get('/health/ready', function (): Response {
                $this->db()->query('SELECT 1 FROM api_tokens LIMIT 0');
                $this->db()->query('SELECT origin_assignment_id,origin_position_id,requester_snapshot FROM tickets LIMIT 0');
                return Response::json(['ready' => true]);
            });
            $secure = function (Request $incoming, callable $next): Response {
                $incoming->user = (new Authenticator($this->db()))->actor($incoming);
                return $next($incoming);
            };
            $router->get('/me', static fn (Request $r) => Response::json($r->user))->middleware($secure);
            $router->get('/tickets', fn (Request $r) => Response::json(
                (new TicketReader($this->db()))->listing($r->user['id'], $r->query)
            ))->middleware($secure);
            $router->get('/ticket-categories', fn (Request $r) => Response::json(
                (new IntakeCatalog($this->db()))->listing($r->user['id'], $r->query, false)
            ))->middleware($secure);
            $router->get('/me/assignments', fn (Request $r) => Response::json(
                (new IntakeCatalog($this->db()))->listing($r->user['id'], $r->query, true)
            ))->middleware($secure);
            $router->post('/tickets', function (Request $r) use ($requestId): Response {
                if (strtolower(trim(explode(';', $r->header('Content-Type') ?? '')[0])) !== 'application/json' || $r->files) {
                    throw new ApiException(415, 'json_required', 'Submit a JSON object without attachments.');
                }
                if ($r->query) {
                    throw new ApiException(422, 'invalid_submission', 'Submission does not accept query parameters.');
                }
                $result = (new TicketSubmission($this->db()))->submit($r->user['id'], $r->body, $r->header('Idempotency-Key') ?? '', $requestId);
                return Response::json(['data'=>$result['ticket'],'meta'=>['replayed'=>$result['replayed']]], 201,
                    ['Location'=>'/api/v1/tickets/'.$result['ticket']['id']]);
            })->middleware($secure);
            $router->get('/tickets/{id}', fn (Request $r) => Response::json(
                (new TicketReader($this->db()))->detail($r->user['id'], $r->params['id'])
            ))->middleware($secure);
            $router->get('/tickets/{id}/timeline', fn (Request $r) => Response::json(
                (new TicketReader($this->db()))->timeline($r->user['id'], $r->params['id'], $r->query)
            ))->middleware($secure);
            if (isset($request->attributes['body_parse_error'])) {
                throw new ApiException(422, 'invalid_json', (string)$request->attributes['body_parse_error']);
            }
            $response = $router->dispatch($request);
        } catch (ApiException $error) {
            $response = Response::json(['error' => $error->getMessage(), 'error_code' => $error->errorCode], $error->status);
        } catch (Throwable $error) {
            error_log(json_encode(['request_id' => $requestId, 'event' => 'request_failed', 'exception' => $error::class]));
            $response = Response::json(['error' => 'Service temporarily unavailable.', 'error_code' => 'service_unavailable'], 503);
        }
        $response->headers += [
            'X-Request-ID' => $requestId,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];
        if ($response->status === 401) {
            $response->headers['WWW-Authenticate'] = 'Bearer';
        }
        if ($response->status === 429) {
            $response->headers['Retry-After'] = '3600';
        }
        if ($request->method === 'HEAD') {
            $response->body = '';
        }
        return $response;
    }

    private function db(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
