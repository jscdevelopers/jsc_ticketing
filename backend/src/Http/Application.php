<?php
declare(strict_types=1);
namespace App\Http;

use App\Config\Database;
use App\Security\Authenticator;
use App\Tickets\TicketReader;
use App\Tickets\TicketSubmission;
use App\Tickets\IntakeCatalog;
use App\Workflow\TaskService;
use App\Workflow\WorkflowService;
use App\Tickets\Collaboration;
use App\Tickets\Reporting;
use App\Security\PasswordAuth;
use App\Support\Sql;
use App\Security\Administration;
use App\Workflow\TaskManagement;
use App\Tickets\Lifecycle;
use App\Tickets\Attachments;
use App\Tickets\Participants;
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
            $router->post('/auth/login',function(Request $r):Response{
                $this->jsonBody($r);$this->secureLogin();
                return Response::json((new PasswordAuth($this->db()))->login($r->body,(string)($_SERVER['REMOTE_ADDR']??'local')));
            });
            $router->post('/auth/verify',function(Request $r):Response{
                $this->jsonBody($r);$this->secureLogin();
                return Response::json((new PasswordAuth($this->db()))->verify($r->body,(string)($_SERVER['REMOTE_ADDR']??'local')));
            });
            $passwordSecure=function(Request $r,callable $next):Response{$r->user=(new Authenticator($this->db()))->actor($r,['access','password_change']);return$next($r);};
            $router->post('/auth/password',function(Request $r):Response{
                $this->jsonBody($r);$this->secureLogin();return Response::json((new PasswordAuth($this->db()))->change($r->user['id'],$r->body));
            })->middleware($passwordSecure);
            $router->post('/auth/logout',function(Request $r):Response{
                $this->jsonBody($r);$token=preg_replace('/^Bearer /i','',$r->header('Authorization')??'');
                Sql::run($this->db,'UPDATE api_tokens SET revoked_at=now() WHERE token_hash=? AND revoked_at IS NULL',[hash('sha256',$token)]);
                Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,reason) VALUES ('SIGNED_OUT',?,'Session revoked')",[$r->user['id']]);
                return Response::json(['signed_out'=>true]);
            })->middleware($passwordSecure);
            $router->get('/health/ready', function (): Response {
                $this->db()->query('SELECT purpose FROM api_tokens LIMIT 0');
                $this->db()->query('SELECT user_id FROM user_credentials LIMIT 0');
                $this->db()->query('SELECT id FROM workflow_executions LIMIT 0');
                $this->db()->query('SELECT run_number FROM tickets LIMIT 0');
                $this->db()->query('SELECT sha256,scan_provider FROM ticket_attachments LIMIT 0');
                $this->db()->query('SELECT active FROM ticket_participants LIMIT 0');
                $this->db()->query('SELECT owner_user_id FROM saved_reports LIMIT 0');
                $this->db()->query('SELECT origin_assignment_id,origin_position_id,requester_snapshot FROM tickets LIMIT 0');
                return Response::json(['ready' => true]);
            });
            $secure = function (Request $incoming, callable $next): Response {
                $incoming->user = (new Authenticator($this->db()))->actor($incoming);
                return $next($incoming);
            };
            $router->get('/me', function(Request $r):Response{
                $permissions=Sql::run($this->db,"SELECT DISTINCT rp.permission_code FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id
                    WHERE ur.user_id=? AND ur.valid_from<=now() AND (ur.valid_to IS NULL OR ur.valid_to>now())
                    AND (rp.permission_code='ticket.read' OR EXISTS(SELECT 1 FROM scope_grants g WHERE g.user_id=ur.user_id AND g.permission_code=rp.permission_code
                        AND g.revoked_at IS NULL AND g.valid_from<=now() AND (g.valid_to IS NULL OR g.valid_to>now())))",[$r->user['id']])->fetchAll(PDO::FETCH_COLUMN);
                return Response::json($r->user+['permissions'=>$permissions]);
            })->middleware($secure);
            $router->get('/admin/schema',fn(Request $r)=>Response::json((new Administration($this->db()))->schema($r->user['id'])))->middleware($secure);
            $router->get('/admin/{resource}',fn(Request $r)=>Response::json((new Administration($this->db()))->listing($r->user['id'],$r->params['resource'],$r->query)))->middleware($secure);
            $router->post('/admin/{resource}',function(Request $r):Response{
                $this->jsonBody($r);return Response::json((new Administration($this->db()))->save($r->user['id'],$r->params['resource'],null,0,$r->header('Idempotency-Key')??'',$r->body),201);
            })->middleware($secure);
            $router->put('/admin/{resource}/{id}',function(Request $r):Response{
                $this->jsonBody($r);return Response::json((new Administration($this->db()))->save($r->user['id'],$r->params['resource'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body));
            })->middleware($secure);
            $router->post('/admin/scope-grants/{id}/revoke',function(Request $r):Response{
                $this->jsonBody($r);return Response::json((new Administration($this->db()))->revoke($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',\App\Support\Mutation::reason($r->body['reason']??null)));
            })->middleware($secure);
            $router->post('/admin/users/{id}/password',function(Request$r):Response{
                $this->jsonBody($r);return Response::json((new Administration($this->db()))->password($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body));
            })->middleware($secure);
            $router->get('/analytics',fn(Request$r)=>Response::json((new \App\Tickets\Analytics($this->db()))->dashboard($r->user['id'],$r->query)))->middleware($secure);
            $router->get('/dashboard',fn(Request $r)=>Response::json((new Reporting($this->db()))->dashboard($r->user['id'],$r->query)))->middleware($secure);
            $router->get('/notifications',fn(Request $r)=>Response::json((new Reporting($this->db()))->notifications($r->user['id'],$r->query)))->middleware($secure);
            $router->get('/shared',fn(Request$r)=>Response::json((new Participants($this->db()))->shared($r->user['id'],$r->query)))->middleware($secure);
            $router->get('/tickets/{id}/summary',fn(Request$r)=>Response::json((new Participants($this->db()))->summary($r->user['id'],$r->params['id'])))->middleware($secure);
            $router->get('/tickets/{id}/participants',fn(Request$r)=>Response::json((new Participants($this->db()))->listing($r->user['id'],$r->params['id'],$r->query)))->middleware($secure);
            $router->get('/tickets/{id}/participant-choices/{kind}',fn(Request$r)=>Response::json((new Participants($this->db()))->choices($r->user['id'],$r->params['id'],$r->params['kind'],$r->query)))->middleware($secure);
            foreach(['participants','privacy']as$audienceAction){$router->post('/tickets/{id}/'.$audienceAction,function(Request$r)use($audienceAction,$requestId):Response{
                $this->jsonBody($r);$service=new Participants($this->db());$method=$audienceAction==='privacy'?'privacy':'change';
                return Response::json($service->$method($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body,$requestId));
            })->middleware($secure);}
            $router->get('/reports',fn(Request$r)=>Response::json((new \App\Tickets\SavedReports($this->db()))->listing($r->user['id'],$r->query)))->middleware($secure);
            $router->get('/reports/choices/{resource}',fn(Request$r)=>Response::json((new Reporting($this->db()))->choices($r->user['id'],$r->params['resource'],$r->query)))->middleware($secure);
            $router->post('/reports',function(Request$r):Response{$this->jsonBody($r);return Response::json((new \App\Tickets\SavedReports($this->db()))->save($r->user['id'],null,0,$r->header('Idempotency-Key')??'',$r->body),201);})->middleware($secure);
            $router->put('/reports/{id}',function(Request$r):Response{$this->jsonBody($r);return Response::json((new \App\Tickets\SavedReports($this->db()))->save($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body));})->middleware($secure);
            $router->get('/reports/export',fn(Request $r)=>(new Reporting($this->db()))->export($r->user['id'],$r->query))->middleware($secure);
            $router->get('/tickets/{id}/progress',fn(Request $r)=>Response::json((new Collaboration($this->db()))->progress($r->user['id'],$r->params['id'],$r->query)))->middleware($secure);
            $router->get('/tickets/{id}/attachments',fn(Request $r)=>Response::json((new Attachments($this->db()))->listing($r->user['id'],$r->params['id'],$r->query)))->middleware($secure);
            $router->get('/attachments/{id}/download',fn(Request $r)=>(new Attachments($this->db()))->download($r->user['id'],$r->params['id']))->middleware($secure);
            $router->post('/tickets/{id}/attachments',function(Request $r)use($requestId):Response{
                if(!str_starts_with(strtolower($r->header('Content-Type')??''),'multipart/form-data;')||$r->query||array_keys($r->files)!==['file']||count($r->files['file'])!==1){throw new ApiException(422,'invalid_upload','Send one multipart file without query parameters.');}
                return Response::json((new Attachments($this->db()))->upload($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->uploadedFile('file'),$r->body,$requestId),201);
            })->middleware($secure);
            foreach(['withdraw','reopen']as$lifeAction){$router->post('/tickets/{id}/'.$lifeAction,function(Request$r)use($lifeAction,$requestId):Response{
                $this->jsonBody($r);return Response::json((new Lifecycle($this->db()))->action($r->user['id'],$r->params['id'],$lifeAction,$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body,$requestId));
            })->middleware($secure);}
            $router->get('/tasks/{id}/candidates',fn(Request$r)=>Response::json((new TaskManagement($this->db()))->candidates($r->user['id'],$r->params['id'],$r->query)))->middleware($secure);
            foreach(['recompute','reassign','manage-release','pause-sla','resume-sla']as$managementAction){$router->post('/tasks/{id}/'.$managementAction,function(Request$r)use($managementAction,$requestId):Response{
                $this->jsonBody($r);return Response::json((new TaskManagement($this->db()))->action($r->user['id'],$r->params['id'],$managementAction,$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body,$requestId));
            })->middleware($secure);}
            $router->get('/tickets/{id}/flow',fn(Request$r)=>Response::json((new Collaboration($this->db()))->flow($r->user['id'],$r->params['id'])))->middleware($secure);
            $router->get('/tickets/{id}/comment-recipients',fn(Request$r)=>Response::json((new Collaboration($this->db()))->recipients($r->user['id'],$r->params['id'],$r->query)))->middleware($secure);
            $router->get('/tickets/{id}/comments',fn(Request $r)=>Response::json((new Collaboration($this->db()))->comments($r->user['id'],$r->params['id'],$r->query)))->middleware($secure);
            foreach(['comments','reply']as$collaborationAction){
                $router->post('/tickets/{id}/'.$collaborationAction,function(Request $r)use($collaborationAction,$requestId):Response{
                    $this->jsonBody($r);return Response::json((new Collaboration($this->db()))->comment($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body,$requestId,$collaborationAction==='reply'));
                })->middleware($secure);
            }
            $router->get('/tasks',fn(Request $r)=>Response::json((new TaskService($this->db()))->listing($r->user['id'],$r->query)))->middleware($secure);
            $router->get('/tickets/{id}/tasks',fn(Request $r)=>Response::json((new TaskService($this->db()))->listing($r->user['id'],$r->query,$r->params['id'])))->middleware($secure);
            foreach(['claim','release','complete','approve','reject','send-back','escalate','redirect','take-over']as$taskAction){
                $router->post('/tasks/{id}/'.$taskAction,function(Request $r)use($taskAction,$requestId):Response{
                    $this->jsonBody($r);
                    return Response::json((new TaskService($this->db()))->action($r->user['id'],$r->params['id'],$taskAction,$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body,$requestId));
                })->middleware($secure);
            }
            $router->get('/workflows',fn(Request $r)=>Response::json((new WorkflowService($this->db()))->listing($r->user['id'],$r->query)))->middleware($secure);
            $router->get('/workflows/choices/{resource}',fn(Request$r)=>Response::json((new WorkflowService($this->db()))->choices($r->user['id'],$r->params['resource'],$r->query)))->middleware($secure);
            $router->post('/workflows',function(Request $r):Response{
                $this->jsonBody($r);return Response::json((new WorkflowService($this->db()))->save($r->user['id'],null,0,$r->header('Idempotency-Key')??'',$r->body),201);
            })->middleware($secure);
            $router->put('/workflows/{id}',function(Request $r):Response{
                $this->jsonBody($r);return Response::json((new WorkflowService($this->db()))->save($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',$r->body));
            })->middleware($secure);
            $router->get('/workflows/{id}/review',fn(Request$r)=>Response::json((new WorkflowService($this->db()))->review($r->user['id'],$r->params['id'])))->middleware($secure);
            $router->post('/workflows/{id}/simulate',function(Request $r):Response{
                $this->jsonBody($r);return Response::json((new WorkflowService($this->db()))->simulate($r->user['id'],$r->params['id'],$r->body));
            })->middleware($secure);
            $router->post('/workflows/{id}/publish',function(Request $r):Response{
                $this->jsonBody($r);if(array_diff(array_keys($r->body),['reason'])){throw new ApiException(422,'invalid_action','Only a reason is accepted.');}
                return Response::json((new WorkflowService($this->db()))->publish($r->user['id'],$r->params['id'],$this->expectedVersion($r),$r->header('Idempotency-Key')??'',\App\Support\Mutation::reason($r->body['reason']??null)));
            })->middleware($secure);
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
        } catch (\PDOException $error) {
            $code=(string)$error->getCode();
            if(in_array($code,['23502','23503','23514','22P02','22007','22008'],true)){$response=Response::json(['error'=>'A required field, relationship or constraint is invalid.','error_code'=>'invalid_record'],422);}
            elseif($code==='23505'){$response=Response::json(['error'=>'An active record with these unique values already exists.','error_code'=>'record_conflict'],409);}
            else{error_log(json_encode(['request_id'=>$requestId,'event'=>'request_failed','exception'=>$error::class]));$response=Response::json(['error'=>'Service temporarily unavailable.','error_code'=>'service_unavailable'],503);}
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
            $response->headers['Retry-After'] = str_contains($request->path,'/auth/')?'900':'3600';
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

    private function jsonBody(Request $request):void
    {
        if(strtolower(trim(explode(';',$request->header('Content-Type')??'')[0]))!=='application/json'||$request->files){throw new ApiException(415,'json_required','A JSON object is required.');}
        if($request->query){throw new ApiException(422,'invalid_query','Mutations do not accept query parameters.');}
    }

    private function expectedVersion(Request $request):int
    {
        $header=$request->header('If-Match')??'';
        if(!preg_match('/^"?([1-9][0-9]{0,8})"?$/D',$header,$matches)){throw new ApiException(428,'version_required','If-Match must contain the current record version.');}
        return (int)$matches[1];
    }

    private function secureLogin():void
    {
        if((getenv('APP_ENV')?:'')==='production'&&($_SERVER['HTTPS']??'')!=='on'){
            throw new ApiException(403,'secure_transport_required','Sign-in requires the approved HTTPS gateway.');
        }
    }
}
