<?php
declare(strict_types=1);

$oldWebhookEnv=getenv('APP_ENV');$oldDemoFlag=getenv('ALLOW_DEMO_INTEGRATIONS');putenv('APP_ENV=test');putenv('ALLOW_DEMO_INTEGRATIONS=true');
try{
    $webhookDefinition=$definition;$webhookDefinition['nodes']=[['id'=>'start','type'=>'start','label'=>'Submitted'],['id'=>'hook','type'=>'webhook','label'=>'Local integration dummy','config'=>['endpoint_id'=>'local-audit']],$definition['nodes'][1],$definition['nodes'][2]];
    $webhookDefinition['edges']=[['from'=>'start','to'=>'hook'],['from'=>'hook','to'=>'work'],['from'=>'work','to'=>'end']];
    new App\Workflow\Graph($webhookDefinition);
    $bad=$webhookDefinition;$bad['nodes'][1]['config']=['endpoint_id'=>'https://127.0.0.1/private'];
    try{new App\Workflow\Graph($bad);expect(false,'workflow URL injection must fail');}catch(InvalidArgumentException){expect(true,'webhook workflow configuration accepts only an allowlisted target identifier');}
    $bad=$webhookDefinition;$bad['nodes'][1]['config']['url']='http://169.254.169.254/';
    try{new App\Workflow\Graph($bad);expect(false,'hidden webhook URL must fail');}catch(InvalidArgumentException){expect(true,'local webhook rejects extra URL and payload configuration');}
    $webhookDraft=$workflows->save(id(106),null,0,'webhook-dummy-draft',['category_id'=>id(16000),'definition'=>$webhookDefinition])['data'];
    expect($workflows->simulate(id(106),$webhookDraft['id'],[])['valid'],'explicitly enabled local dummy can be simulated');
    putenv('APP_ENV=production');
    try{$workflows->simulate(id(106),$webhookDraft['id'],[]);expect(false,'production must not enable dummy');}catch(App\Http\ApiException$error){expect($error->status===422,'production rejects local webhook dummy publication');}
    putenv('APP_ENV=test');$workflows->publish(id(101),$webhookDraft['id'],1,'webhook-dummy-publish','Approved local-only integration double');
    $webhookTicket=$submission->submit(id(15000),$workflowBody,'webhook-dummy-ticket','test')['ticket'];
    $hookEvent=sql("SELECT event_data FROM ticket_events WHERE ticket_id=? AND event_type='WEBHOOK_DEMO_RECORDED'",[$webhookTicket['id']])->fetchColumn();
    expect(json_decode($hookEvent,true)['external_delivery']===false&&$webhookTicket['status']==='routed','dummy records no-delivery status and continues the workflow atomically');
    $submission->submit(id(15000),$workflowBody,'webhook-dummy-ticket','test');
    expect((int)sql("SELECT count(*) FROM ticket_events WHERE ticket_id=? AND event_type='WEBHOOK_DEMO_RECORDED'",[$webhookTicket['id']])->fetchColumn()===1,'idempotent intake cannot duplicate the local integration event');
}finally{putenv($oldWebhookEnv===false?'APP_ENV':'APP_ENV='.$oldWebhookEnv);putenv($oldDemoFlag===false?'ALLOW_DEMO_INTEGRATIONS':'ALLOW_DEMO_INTEGRATIONS='.$oldDemoFlag);}
