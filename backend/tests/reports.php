<?php
declare(strict_types=1);

$reportReader=id(19000);sql("INSERT INTO app_users(id,external_subject,display_name) VALUES (?,'report:revocable','Report Reader')",[$reportReader]);$readGrant=$admin->grantRead($reportReader,'station',id(11),'Temporary station report scope');
$savedReports=new App\Tickets\SavedReports($db);$reporting=new App\Tickets\Reporting($db);
$reportBody=['name'=>'My operational requests','filters'=>['requester'=>'me','priority'=>'normal'],'active'=>true,'reason'=>'Approved personal reporting purpose'];
$saved=$savedReports->save(id(15000),null,0,'report-personal',$reportBody)['data'];
expect(count($savedReports->listing(id(15000),[])['data'])===1&&count($savedReports->listing(id(17001),[])['data'])===0,'saved definitions belong only to their owner');
try{$savedReports->save(id(17001),$saved['id'],1,'report-foreign',$reportBody);expect(false,'foreign report edit must fail');}catch(App\Http\ApiException$error){expect($error->status===404,'another user cannot edit a saved report');}
$savedAgain=$savedReports->save(id(15000),null,0,'report-personal',$reportBody);expect($savedAgain['meta']['replayed'],'saved report creation is idempotent');
$badReport=$reportBody;$badReport['filters']['sql']='true';
try{$savedReports->save(id(15000),null,0,'report-injection',$badReport);expect(false,'unsupported report expression must fail');}catch(App\Http\ApiException$error){expect($error->status===422,'report filters cannot contain arbitrary expressions');}
$personal=(new App\Tickets\TicketReader($db))->listing(id(15000),['requester'=>'me','priority'=>'normal']);
expect(count($personal['data'])>0&&count($personal['data'])<=10,'report results use the paged authorized ticket path');
expect(count((new App\Tickets\TicketReader($db))->listing($reportReader,['station_id'=>id(12)])['data'])===0,'scope filtering never expands current read authority');
expect(count($reporting->choices($reportReader,'stations',[])['data'])===1,'report selectors reveal only scopes containing currently readable tickets');
sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,'report.export','station',?,'Scoped export test')",[id(106),id(11)]);
$exportId=$submission->submit(id(15000),[...$workflowBody,'subject'=>'=Spreadsheet formula (escaped) \\ fixture'],'export-fixture','test')['ticket']['id'];
$csv=$reporting->export(id(106),['station_id'=>id(11),'q'=>'Spreadsheet formula']);
expect(str_contains($csv->body,"'=Spreadsheet formula")&&!str_contains($csv->body,'Ticket 2'),'CSV filters and formula escaping apply after record authorization');
$pdf=$reporting->export(id(106),['station_id'=>id(11),'q'=>'Spreadsheet formula','format'=>'pdf']);
expect($pdf->headers['Content-Type']==='application/pdf'&&str_starts_with($pdf->body,'%PDF-1.4')&&str_ends_with($pdf->body,"%%EOF\n"),'PDF is a real downloadable PDF document');
preg_match('/startxref\n([0-9]+)\n/',$pdf->body,$xref);
expect(substr($pdf->body,(int)$xref[1],4)==='xref'&&str_contains($pdf->body,'\\(escaped\\) \\\\ fixture'),'PDF cross-reference and literal text escaping are valid');
$many=[];for($i=0;$i<100;$i++){$many[]=['reference'=>'PDF-'.$i,'subject'=>'Unicode '."\u{00E9}".' and newline '."\n".' / parenthesis (safe)','status'=>'closed','priority'=>'normal','created_at'=>'2026-11-23 00:00:00+00'];}
$largePdf=App\Support\PdfReport::render($many);preg_match('/\/Type \/Pages \/Count ([0-9]+)/',$largePdf,$pages);expect((int)$pages[1]>1,'large authorized PDF reports paginate');
$reportGrant=sql("SELECT id FROM scope_grants WHERE user_id=? AND permission_code='report.export'",[id(106)])->fetchColumn();
$admin->revoke('grant',$reportGrant,'Export authority ended');
try{$reporting->export(id(106),['format'=>'pdf']);expect(false,'revoked export must fail');}catch(App\Http\ApiException$error){expect($error->status===403,'CSV and PDF require current export authority on every run');}
$editedReport=$savedReports->save(id(15000),$saved['id'],1,'report-archive',[...$reportBody,'active'=>false,'reason'=>'No longer needed'])['data'];
expect(!$editedReport['active']&&count($savedReports->listing(id(15000),[])['data'])===0,'saved reports archive reversibly without deleting ticket data');
try{$savedReports->save(id(15000),$saved['id'],1,'report-stale',$reportBody);expect(false,'stale report edit must fail');}catch(App\Http\ApiException$error){expect($error->status===409,'saved report edits have optimistic version guards');}

$owned=$savedReports->save($reportReader,null,0,'report-scoped',['name'=>'Station scoped report','filters'=>['station_id'=>id(11)],'active'=>true,'reason'=>'Approved temporary scope'])['data'];
expect(count((new App\Tickets\TicketReader($db))->listing($reportReader,(array)$owned['filters'])['data'])>0,'saved report uses current approved scope');
$admin->revoke('grant',$readGrant,'Read authority ended');
expect(count((new App\Tickets\TicketReader($db))->listing($reportReader,(array)$owned['filters'])['data'])===0&&count($savedReports->listing($reportReader,[])['data'])===1,'saved filters remain private while revoked data disappears immediately');
