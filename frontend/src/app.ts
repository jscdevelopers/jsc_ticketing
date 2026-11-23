import { Component, Directive, AfterViewInit, ElementRef, ViewChild, inject, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import Swal from 'sweetalert2/dist/sweetalert2.esm.all.js';
import type { SweetAlertOptions } from 'sweetalert2';
import { Api } from './api';

@Component({selector:'app-info',standalone:true,template:`<span class="info-wrap"><button type="button" class="info" [attr.aria-label]="text" [attr.aria-describedby]="id"><i aria-hidden="true" class="bi bi-info-circle"></i></button><span role="tooltip" class="info-popover" [id]="id">{{text}}</span></span>`})
export class Info { @Input() text='';id='info-'+crypto.randomUUID(); }

@Directive({selector:'input,select,textarea',standalone:true})
export class FormLabel implements AfterViewInit {
  private element=inject(ElementRef<HTMLInputElement>);
  ngAfterViewInit():void{
    const input=this.element.nativeElement;if(input.closest('label')||input.hasAttribute('aria-label'))return;
    const previous=input.previousElementSibling;
    if(previous?.tagName==='LABEL'){if(!input.id)input.id='field-'+crypto.randomUUID();(previous as HTMLLabelElement).htmlFor=input.id;}
  }
}

@Component({selector:'app-root',standalone:true,imports:[CommonModule,FormsModule,Info,FormLabel],templateUrl:'./app.html'})
export class App {
  api=inject(Api);
  @ViewChild('shareDialog') shareDialog?:ElementRef<HTMLDialogElement>;
  @ViewChild('formDialog') dialog?:ElementRef<HTMLDialogElement>;
  user:any=null;page='dashboard';busy=false;loading=false;error='';mobileMenu=false;
  username='';password='';newPassword='';confirmPassword='';code='';challenge:any=null;mustChange=false;
  loginWithToken=false;operatorToken='';
  rows:any[]=[];next:string|null=null;query='';status='';dashboard:any=null;
  detail:any=null;tasks:any[]=[];events:any[]=[];comments:any[]=[];progress:any[]=[];
  eventNext:string|null=null;commentNext:string|null=null;taskNext:string|null=null;
  summary:any=null;participants:any[]=[];participantNext:string|null=null;shareDraft:any={kind:'user',participation:'cc',targets:[],reason:''};shareChoices:any[]=[];shareNext:string|null=null;shareQuery='';
  defaultCc:string[]=[];workflowCalendar:any=null;workflowHolidays='';workflowEditable=true;workflowDiff:any=null;
  note='';noteSensitivity='public_internal';attachments:any[]=[];attachmentNext:string|null=null;uploadFile:File|null=null;fileClassification='internal';
  modal='';modalTitle='';draft:any={};assignments:any[]=[];categories:any[]=[];categorySchema:any=null;
  assignmentSearch='';categorySearch='';assignmentNext:string|null=null;categoryNext:string|null=null;
  resource='provinces';resourceSchema:Record<string,Record<string,string>>={};recordFields:{key:string;type:string}[]=[];
  references:Record<string,any[]>={};refQueries:Record<string,string>={};refNext:Record<string,string|null>={};
  workflow:any=null;wizardStep=0;workflowCategory='';workflowTitle='';workflowFields:any[]=[];nodes:any[]=[];edges:any[]=[];
  workflowSchemaRequired:string[]=[];requireSecond=true;simulation:any=null;simCategory='';simPriority='normal';
  help=true;timezone='Africa/Harare';dashboardScope='personal';
  private loadSerial=0;private debounceTimer:any;private choiceTimers:Record<string,any>={};
  pages=[{id:'dashboard',name:'Overview',icon:'grid-1x2'},{id:'tickets',name:'My tickets',icon:'ticket-detailed'},
    {id:'tasks',name:'My actions',icon:'inbox'},{id:'shared',name:'Shared with me',icon:'people'},{id:'queues',name:'Work queues',icon:'collection'},{id:'sla',name:'SLA monitor',icon:'alarm'},{id:'workflows',name:'Workflows',icon:'diagram-3'},
    {id:'directory',name:'Administration',icon:'building'},{id:'notifications',name:'Notifications',icon:'bell'},{id:'preferences',name:'Preferences',icon:'sliders'}];
  resources=['provinces','stations','departments','positions','users','assignments','categories','user-roles','scope-grants','roles','permissions','audit'];
  wizardSteps=['Basics','Form','Routing','Stages','Policies','Simulation','Review'];
  nodeTypes=['task','claim_pool','approval','decision','route','parallel_fork','parallel_join','request_info','timer','escalation','notification','resolve','close','end'];
  constructor(){try{const p=JSON.parse(localStorage.getItem('jsc-preferences')??'{}');this.help=p.help??true;this.timezone=p.timezone??'Africa/Harare';}catch{} }
  private alert(options:SweetAlertOptions){return Swal.fire({...options,target:this.dialog?.nativeElement.open?this.dialog.nativeElement:this.shareDialog?.nativeElement.open?this.shareDialog.nativeElement:document.body});}
  can(permission:string):boolean{return !!this.user?.permissions?.includes(permission);}
  visiblePage(id:string):boolean{if(['queues','sla'].includes(id))return this.can('task.manage');return id!=='workflows'&&id!=='directory'||id==='workflows'&&(this.can('workflow.design')||this.can('workflow.publish'))||id==='directory'&&(this.can('directory.manage')||this.can('security.manage'));}
  title(id=this.page):string{return this.pages.find(p=>p.id===id)?.name??(id==='summary'?'Shared summary':'Ticket detail');}
  label(key:string):string{return key.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());}
  date(value:any):string{if(!value)return '—';try{return new Intl.DateTimeFormat('en-ZW',{dateStyle:'medium',timeStyle:'short',timeZone:this.timezone}).format(new Date(value));}catch{return String(value);}}
  entries(object:any):[string,any][]{return Object.entries(object??{});}
  display(row:any):string{return row?.name??row?.display_name??row?.title??row?.code??row?.event_type??row?.id??'';}
  track(_:number,row:any):any{return row.id??row.key;}
  trackEntry(_:number,pair:[string,any]):string{return pair[0];}
  toast(message:string,icon:'success'|'error'|'info'='success'):void{void this.alert({toast:true,position:'bottom-end',icon,title:message,showConfirmButton:false,timer:4000,timerProgressBar:true});}
  failed(error:any):void{const message=error?.error?.message??(error?.status===0?'Connection lost. Your draft is saved; retry when connected.':'The request could not be completed.');this.error=message;this.toast(message,'error');}
  async signIn():Promise<void>{
    if(this.busy)return;this.busy=true;this.error='';
    try{
      if(this.loginWithToken){this.api.token=this.operatorToken;this.operatorToken='';await this.loadUser();return;}
      const result=await this.api.post('/auth/login',{username:this.username,password:this.password});
      if(result.data.challenge){this.challenge=result.data;this.code='';return;}
      this.acceptLogin(result.data);if(!this.mustChange){await this.loadUser();}
    }catch(e){this.api.clear();this.failed(e);}finally{this.busy=false;}
  }
  private acceptLogin(data:any):void{this.api.token=data.token;this.mustChange=!!data.must_change_password;if(!this.mustChange)this.password='';}
  async verifyFactor():Promise<void>{
    if(this.busy)return;this.busy=true;
    try{const result=await this.api.post('/auth/verify',{challenge:this.challenge.challenge,code:this.code});this.acceptLogin(result.data);this.challenge=null;this.code='';if(!this.mustChange)await this.loadUser();}catch(e){this.failed(e);}finally{this.busy=false;}
  }
  async changePassword():Promise<void>{
    if(this.newPassword!==this.confirmPassword){this.toast('New passwords do not match.','error');return;}
    this.busy=true;try{await this.api.post('/auth/password',{current_password:this.password,new_password:this.newPassword});this.api.clear();this.mustChange=false;this.password='';this.newPassword='';this.confirmPassword='';this.toast('Password changed. Sign in with your new password.');}catch(e){this.failed(e);}finally{this.busy=false;}
  }
  async loadUser():Promise<void>{this.user=(await this.api.get('/me')).data;this.challenge=null;this.mustChange=false;await this.navigate('dashboard');}
  async signOut():Promise<void>{clearTimeout(this.debounceTimer);++this.loadSerial;if(this.shareDialog?.nativeElement.open)this.closeSharing();try{await this.api.post('/auth/logout');}catch{}this.closeModal();this.api.clear();this.user=null;this.detail=null;this.password='';this.challenge=null;this.mustChange=false;this.dashboardScope='personal';this.shareChoices=[];this.shareDraft={kind:'user',participation:'cc',targets:[],reason:''};}
  async navigate(page:string):Promise<void>{clearTimeout(this.debounceTimer);++this.loadSerial;this.page=page;this.mobileMenu=false;this.query='';this.status='';this.rows=[];this.next=null;this.error='';this.detail=null;this.summary=null;await this.load();}
  search():void{clearTimeout(this.debounceTimer);this.debounceTimer=setTimeout(()=>{void this.load();},250);}
  async load(more=false):Promise<void>{
    if(['detail','summary'].includes(this.page))return;const serial=++this.loadSerial;this.loading=true;this.error='';
    try{
      if(this.page==='dashboard'){this.dashboard=(await this.api.get('/dashboard',{scope:this.dashboardScope})).data;return;}
      if(this.page==='preferences')return;
      if(this.page==='directory'&&!Object.keys(this.resourceSchema).length){this.resourceSchema=(await this.api.get('/admin/schema')).data;}
      const path=this.page==='tickets'?'/tickets':['tasks','queues','sla'].includes(this.page)?'/tasks':this.page==='workflows'?'/workflows':this.page==='directory'?'/admin/'+this.resource:this.page==='shared'?'/shared':'/notifications';
      const q:Record<string,string>={};if(this.query&&this.page!=='notifications')q['q']=this.query;if(this.status&&['tickets','tasks','queues','sla'].includes(this.page))q['status']=this.status;
      if(['queues','sla'].includes(this.page))q['view']=this.page==='sla'?'overdue':'authorized';
      if(more&&this.next)q[this.page==='tickets'?'cursor':this.page==='notifications'?'before':'after']=this.next;
      const result=await this.api.get(path,q);if(serial!==this.loadSerial)return;
      this.rows=more?[...this.rows,...result.data]:result.data;this.next=result.meta.next_cursor??result.meta.next_after??result.meta.next_before??null;
    }catch(e){if(serial===this.loadSerial)this.failed(e);}finally{if(serial===this.loadSerial)this.loading=false;}
  }
  async openTicket(id:string):Promise<void>{
    clearTimeout(this.debounceTimer);const serial=++this.loadSerial;this.summary=null;this.loading=true;this.error='';
    try{
      if(this.detail?.id!==id){this.note='';try{this.note=localStorage.getItem(this.draftKey('comment:'+id))??'';}catch{}this.uploadFile=null;}
      let detail:any;try{detail=(await this.api.get('/tickets/'+id)).data;}catch(error:any){if(error.status!==404)throw error;const summary=(await this.api.get('/tickets/'+id+'/summary')).data;if(serial!==this.loadSerial)return;this.summary=summary;this.detail=null;this.page='summary';return;}
      if(serial!==this.loadSerial)return;this.detail=detail;this.page='detail';
      const [tasks,events,comments,progress,files,members]=await Promise.all([this.api.get('/tickets/'+id+'/tasks'),this.api.get('/tickets/'+id+'/timeline'),this.api.get('/tickets/'+id+'/comments'),this.api.get('/tickets/'+id+'/progress'),this.api.get('/tickets/'+id+'/attachments'),this.api.get('/tickets/'+id+'/participants')]);
      if(serial!==this.loadSerial)return;
      this.tasks=tasks.data;this.taskNext=tasks.meta.next_after??null;this.events=events.data;this.eventNext=events.meta.next_after??null;this.comments=comments.data;this.commentNext=comments.meta.next_after??null;this.progress=progress.data;this.attachments=files.data;this.attachmentNext=files.meta.next_after??null;this.participants=members.data;this.participantNext=members.meta.next_after??null;
    }catch(e){if(serial===this.loadSerial)this.failed(e);}finally{if(serial===this.loadSerial)this.loading=false;}
  }
  async moreDetail(kind:'events'|'comments'|'tasks'):Promise<void>{
    const cursor=kind==='events'?this.eventNext:kind==='comments'?this.commentNext:this.taskNext;if(!cursor)return;
    const route=kind==='events'?'timeline':kind;this.loading=true;try{const result=await this.api.get('/tickets/'+this.detail.id+'/'+route,{after:cursor});
      if(kind==='events'){this.events.push(...result.data);this.eventNext=result.meta.next_after??null;}else if(kind==='comments'){this.comments.push(...result.data);this.commentNext=result.meta.next_after??null;}else{this.tasks.push(...result.data);this.taskNext=result.meta.next_after??null;}
    }catch(e){this.failed(e);}finally{this.loading=false;}
  }
  async taskAction(task:any,action:string):Promise<void>{
    const response=await this.alert({title:this.label(action)+' task',text:action==='claim'?'You will become responsible for this task.':'The action and your reason will be added to the ticket history.',icon:'question',showCancelButton:true,confirmButtonText:this.label(action),confirmButtonColor:'#14633e',...(action==='claim'?{}:{input:'textarea' as const,inputLabel:'Reason *',inputValidator:(value:string)=>value.trim().length<3?'Enter a practical reason.':null})});
    if(!response.isConfirmed)return;this.busy=true;
    try{await this.api.post('/tasks/'+task.id+'/'+action,action==='claim'?{}:{reason:response.value},Number(task.version));this.toast('Task '+this.label(action).toLowerCase()+' recorded.');if(this.detail)await this.openTicket(this.detail.id);else await this.load();}catch(e){this.failed(e);if(this.detail)await this.openTicket(this.detail.id);else await this.load();}finally{this.busy=false;}
  }
  async addComment(reply=false):Promise<void>{
    if(!this.note.trim()||this.busy)return;this.busy=true;
    try{await this.api.post('/tickets/'+this.detail.id+'/'+(reply?'reply':'comments'),{body:this.note,sensitivity:this.noteSensitivity},Number(this.detail.version));this.note='';localStorage.removeItem(this.draftKey('comment:'+this.detail.id));this.toast(reply?'Reply recorded.':'Comment added.');await this.openTicket(this.detail.id);}catch(e){this.failed(e);}finally{this.busy=false;}
  }
  async loadParticipants(more=false):Promise<void>{const r=await this.api.get('/tickets/'+this.detail.id+'/participants',more&&this.participantNext?{after:this.participantNext}:{});this.participants=more?[...this.participants,...r.data]:r.data;this.participantNext=r.meta.next_after??null;}
  async openSharing():Promise<void>{try{this.shareDraft=JSON.parse(localStorage.getItem(this.draftKey('sharing:'+this.detail.id))??'null')??{kind:'user',participation:'cc',targets:[],reason:''};}catch{this.shareDraft={kind:'user',participation:'cc',targets:[],reason:''};}this.shareQuery='';this.shareDialog?.nativeElement.showModal();await this.loadShareChoices();}
  rememberSharing():void{localStorage.setItem(this.draftKey('sharing:'+this.detail.id),JSON.stringify(this.shareDraft));}
  closeSharing():void{this.rememberSharing();this.shareDialog?.nativeElement.close();}
  async loadShareChoices(more=false):Promise<void>{try{const r=await this.api.get('/tickets/'+this.detail.id+'/participant-choices/'+(this.shareDraft.kind==='user'?'users':'departments'),{q:this.shareQuery,...(more&&this.shareNext?{after:this.shareNext}:{})});this.shareChoices=more?[...this.shareChoices,...r.data]:r.data;this.shareNext=r.meta.next_after??null;}catch(e){this.failed(e);}}
  selectShare(choice:any):void{if(this.shareDraft.targets.length>=10){this.toast('Select at most ten recipients per change.','info');return;}if(!this.shareDraft.targets.some((t:any)=>t.id===choice.id&&t.kind===this.shareDraft.kind&&t.participation===this.shareDraft.participation))this.shareDraft.targets.push({id:choice.id,name:choice.name,kind:this.shareDraft.kind,participation:this.shareDraft.participation,active:true});this.rememberSharing();}
  async saveSharing():Promise<void>{if(!(await this.alert({title:'Share ticket summaries?',text:'Recipients can see reference, category, department and status. This does not grant task or attachment access.',showCancelButton:true,confirmButtonText:'Share summaries',icon:'question'})).isConfirmed)return;this.busy=true;try{await this.api.post('/tickets/'+this.detail.id+'/participants',{reason:this.shareDraft.reason,targets:this.shareDraft.targets.map(({name,...t}:any)=>t)},Number(this.detail.version));localStorage.removeItem(this.draftKey('sharing:'+this.detail.id));this.shareDialog?.nativeElement.close();this.toast('Summary recipients updated.');await this.openTicket(this.detail.id);}catch(e){this.failed(e);}finally{this.busy=false;}}
  async revokeParticipant(member:any):Promise<void>{const r=await this.alert({title:'Revoke this shared summary?',input:'textarea',inputLabel:'Reason *',inputValidator:v=>v.trim().length<3?'Reason required.':null,showCancelButton:true,confirmButtonText:'Revoke',icon:'warning'});if(!r.isConfirmed)return;this.busy=true;try{await this.api.post('/tickets/'+this.detail.id+'/participants',{reason:r.value,targets:[{id:member.user_id??member.department_id,kind:member.user_id?'user':'department',participation:member.participation,active:false}]},Number(this.detail.version));this.toast('Shared summary revoked.');await this.openTicket(this.detail.id);}catch(e){this.failed(e);}finally{this.busy=false;}}
  async changePrivacy():Promise<void>{const classification=await this.alert({title:'Change ticket privacy',text:'Restricted or confidential classification immediately removes summary-only visibility and requires sensitive clearance.',input:'select',inputLabel:'Classification *',inputOptions:{internal:'Internal',restricted:'Restricted',confidential:'Confidential'},inputValue:this.detail.confidentiality,showCancelButton:true,confirmButtonText:'Continue'});if(!classification.isConfirmed)return;const reason=await this.alert({title:'Confirm privacy change',input:'textarea',inputLabel:'Reason *',inputValidator:v=>v.trim().length<3?'Reason required.':null,showCancelButton:true,confirmButtonText:'Change privacy',icon:'warning'});if(!reason.isConfirmed)return;this.busy=true;try{await this.api.post('/tickets/'+this.detail.id+'/privacy',{classification:classification.value,reason:reason.value},Number(this.detail.version));this.toast('Privacy classification updated.');await this.openTicket(this.detail.id);}catch(e){this.failed(e);}finally{this.busy=false;}}
  rememberComment():void{if(this.detail)localStorage.setItem(this.draftKey('comment:'+this.detail.id),this.note);}
  async loadAttachments(more=false):Promise<void>{const r=await this.api.get('/tickets/'+this.detail.id+'/attachments',more&&this.attachmentNext?{after:this.attachmentNext}:{});this.attachments=more?[...this.attachments,...r.data]:r.data;this.attachmentNext=r.meta.next_after??null;}
  chooseFile(event:Event):void{this.uploadFile=(event.target as HTMLInputElement).files?.[0]??null;}
  async uploadAttachment():Promise<void>{if(!this.uploadFile||this.busy)return;this.busy=true;try{await this.api.upload('/tickets/'+this.detail.id+'/attachments',this.uploadFile,this.fileClassification,Number(this.detail.version));this.uploadFile=null;this.toast('File stored privately and quarantined for checking.');await this.openTicket(this.detail.id);}catch(e){this.failed(e);}finally{this.busy=false;}}
  async downloadAttachment(file:any):Promise<void>{try{await this.api.downloadFile(file.id,file.file_name);}catch(e){this.failed(e);}}
  async lifecycle(action:string):Promise<void>{const r=await this.alert({title:this.label(action)+' ticket?',text:action==='withdraw'?'Active work will be cancelled and retained in history.':'A new run will use the original workflow version.',input:'textarea',inputLabel:'Reason *',inputValidator:v=>v.trim().length<3?'Reason required.':null,showCancelButton:true,confirmButtonText:this.label(action),icon:'warning'});if(!r.isConfirmed)return;this.busy=true;try{await this.api.post('/tickets/'+this.detail.id+'/'+action,{reason:r.value},Number(this.detail.version));this.toast('Ticket '+action+' recorded.');await this.openTicket(this.detail.id);}catch(e){this.failed(e);}finally{this.busy=false;}}
  async manageTask(task:any,action:string):Promise<void>{await this.taskAction(task,action);}
  async reassignTask(task:any):Promise<void>{
    const search=await this.alert({title:'Find eligible staff',text:'Search current eligible staff by name. Up to ten matches are shown.',input:'text',inputLabel:'Staff name',showCancelButton:true,confirmButtonText:'Search'});if(!search.isConfirmed)return;
    try{const rows=(await this.api.get('/tasks/'+task.id+'/candidates',{eligible:'1',q:search.value??''})).data;if(!rows.length){this.toast('No eligible staff matched. Try another name.','info');return;}
      const options:Record<string,string>={};for(const row of rows)options[row.id]=row.display_name;
      const target=await this.alert({title:'Reassign responsibility',input:'select',inputLabel:'Eligible staff *',inputOptions:options,inputPlaceholder:'Select a staff member',inputValidator:v=>!v?'Choose an eligible staff member.':null,showCancelButton:true,confirmButtonText:'Continue'});if(!target.isConfirmed)return;
      const reason=await this.alert({title:'Confirm reassignment',text:'The prior claimant and your reason remain in the audit history.',input:'textarea',inputLabel:'Reason *',inputValidator:v=>v.trim().length<3?'Reason required.':null,showCancelButton:true,confirmButtonText:'Reassign',icon:'warning'});if(!reason.isConfirmed)return;
      this.busy=true;await this.api.post('/tasks/'+task.id+'/reassign',{target_user_id:target.value,reason:reason.value},Number(task.version));this.toast('Task reassigned.');await this.openTicket(task.ticket_id);
    }catch(e){this.failed(e);}finally{this.busy=false;}
  }

  private draftKey(kind:string):string{return 'jsc-draft:'+this.user?.id+':'+kind;}
  remember():void{if(this.modal==='ticket'||this.modal==='record'){localStorage.setItem(this.draftKey(this.modal==='record'?'record:'+this.resource+':'+(this.draft.id??'new'):'ticket'),JSON.stringify(this.draft));}if(this.modal==='workflow')this.rememberWorkflow();}
  private showModal():void{queueMicrotask(()=>this.dialog?.nativeElement.showModal());}
  closeModal():void{if(this.modal)this.remember();this.dialog?.nativeElement.close();this.modal='';}
  async resetDraft():Promise<void>{if(!(await this.alert({title:'Reset this form?',text:'The saved unfinished input will be cleared.',showCancelButton:true,icon:'warning',confirmButtonText:'Reset'})).isConfirmed)return;const kind=this.modal;const key=this.draftKey(kind==='ticket'?'ticket':kind==='workflow'?'workflow':'record:'+this.resource+':'+(this.draft.id??'new'));this.closeModal();localStorage.removeItem(key);this.draft={};if(kind==='ticket')await this.newTicket();else if(kind==='workflow')await this.newWorkflow();else await this.editRecord();}
  async newTicket():Promise<void>{
    this.modal='ticket';this.modalTitle='New ticket';try{this.draft=JSON.parse(localStorage.getItem(this.draftKey('ticket'))??'null')??{assignment_id:'',category_id:'',subject:'',description:'',priority:'normal',fields:{}};}catch{this.draft={assignment_id:'',category_id:'',subject:'',description:'',priority:'normal',fields:{}};}
    this.categorySchema=null;this.showModal();await Promise.all([this.loadAssignments(),this.loadCategories()]);await this.selectCategory(false);
  }
  private async preserveChoice(path:string,rows:any[],id:string,key='id'):Promise<any[]>{if(!id||rows.some(r=>r[key]===id))return rows;const selected=(await this.api.get(path,{id})).data;return [...selected,...rows];}
  searchChoice(kind:string):void{clearTimeout(this.choiceTimers[kind]);this.choiceTimers[kind]=setTimeout(()=>{if(kind==='sharing')void this.loadShareChoices();else if(kind==='assignments')void this.loadAssignments();else if(kind==='categories')void this.loadCategories();else if(kind.startsWith('workflow:'))void this.loadWorkflowChoice(kind.slice(9));else void this.loadReference(kind);},250);}
  async loadAssignments(more=false):Promise<void>{try{const q:any={q:this.assignmentSearch};if(more&&this.assignmentNext)q.after=this.assignmentNext;const r=await this.api.get('/me/assignments',q);this.assignments=await this.preserveChoice('/me/assignments',more?[...this.assignments,...r.data]:r.data,this.draft.assignment_id);this.assignmentNext=r.meta.next_after??null;}catch(e){this.failed(e);}}
  async loadCategories(more=false):Promise<void>{try{const q:any={q:this.categorySearch};if(more&&this.categoryNext)q.after=this.categoryNext;const r=await this.api.get('/ticket-categories',q);this.categories=await this.preserveChoice('/ticket-categories',more?[...this.categories,...r.data]:r.data,this.draft.category_id);this.categoryNext=r.meta.next_after??null;}catch(e){this.failed(e);}}
  async selectCategory(clear=true):Promise<void>{const selected=this.categories.find(c=>c.id===this.draft.category_id);this.categorySchema=selected?.form_schema??null;if(clear){this.draft.fields={};for(const[key,rule]of this.entries(this.categorySchema?.properties)){if(rule.type==='boolean')this.draft.fields[key]=false;}}this.remember();}
  async submitTicket():Promise<void>{this.busy=true;this.remember();try{const r=await this.api.post('/tickets',this.draft);localStorage.removeItem(this.draftKey('ticket'));this.dialog?.nativeElement.close();this.modal='';this.toast('Ticket '+r.data.reference+' submitted.');await this.openTicket(r.data.id);}catch(e){this.failed(e);}finally{this.busy=false;}}
  fieldLabel(key:string,rule:any):string{return rule.title??this.label(key);}
  requiredField(key:string):boolean{return !!this.categorySchema?.required?.includes(key);}
  fieldValue(key:string,value:any,rule:any):void{if(value===''&&(rule.type==='integer'||rule.type==='number'))delete this.draft.fields[key];else this.draft.fields[key]=rule.type==='integer'||rule.type==='number'?Number(value):value;this.remember();}
  async editRecord(record?:any):Promise<void>{
    if(!this.resourceSchema[this.resource])return;this.modal='record';this.modalTitle=(record?'Edit ':'Create ')+this.label(this.resource);this.recordFields=Object.entries(this.resourceSchema[this.resource]).map(([key,type])=>({key,type}));
    let data:any=record?{...record}:{};
    for(const f of this.recordFields){if(!record)data[f.key]=f.type==='boolean'?f.key==='active':f.type.startsWith('nullable_')?null:'';if(f.type.includes('date')&&data[f.key]){const date=new Date(data[f.key]);data[f.key]=new Date(date.getTime()-date.getTimezoneOffset()*60000).toISOString().slice(0,16);}}
    try{data=JSON.parse(localStorage.getItem(this.draftKey('record:'+this.resource+':'+(data.id??'new')))??'null')??data;}catch{}
    this.draft={...data,reason:''};this.references={};this.refQueries={};this.refNext={};this.showModal();
    for(const field of this.recordFields){if(this.referenceResource(field.key))await this.loadReference(field.key);}
  }
  referenceResource(key:string):string|null{return ({province_id:'provinces',station_id:'stations',department_id:'departments',position_id:'positions',user_id:'users',role_id:'roles',parent_id:'categories',category_id:'categories',permission_code:'permissions'}as any)[key]??(key==='scope_ref_id'?({station:'stations',province:'provinces',department:'departments'}as any)[this.draft.scope_kind]??null:null);}
  async loadReference(key:string,more=false):Promise<void>{const resource=this.referenceResource(key);if(!resource)return;try{const q:any={q:this.refQueries[key]??''};if(more&&this.refNext[key])q.after=this.refNext[key];const r=await this.api.get('/admin/'+resource,q);this.references[key]=await this.preserveChoice('/admin/'+resource,more?[...(this.references[key]??[]),...r.data]:r.data,this.draft[key],key==='permission_code'?'code':'id');this.refNext[key]=r.meta.next_after??null;}catch(e){this.failed(e);}}
  async saveRecord():Promise<void>{if((this.draft.id&&this.draft.active===false||['scope-grants','user-roles'].includes(this.resource))&&!(await this.alert({title:'Apply this authority or lifecycle change?',text:'The change takes effect immediately and is added to the audit history.',showCancelButton:true,confirmButtonText:'Apply change',icon:'warning'})).isConfirmed)return;this.busy=true;this.remember();try{
    const body:any={reason:this.draft.reason};for(const field of this.recordFields){let value=this.draft[field.key];if(field.type.startsWith('nullable_')&&(value===''||value===undefined))value=null;if(field.type.includes('date')&&value)value=new Date(value).toISOString();body[field.key]=value;}
    await this.api.post('/admin/'+this.resource+(this.draft.id?'/'+this.draft.id:''),body,this.draft.id?Number(this.draft.version):undefined,this.draft.id?'PUT':'POST');
    localStorage.removeItem(this.draftKey('record:'+this.resource+':'+(this.draft.id??'new')));this.dialog?.nativeElement.close();this.modal='';this.toast('Record saved.');await this.load();
  }catch(e){this.failed(e);}finally{this.busy=false;}}
  async revokeGrant(row:any):Promise<void>{const r=await this.alert({title:'Revoke this scope grant?',text:'This removes the granted authority immediately.',input:'textarea',inputLabel:'Reason *',inputValidator:v=>v.trim().length<3?'Reason required.':null,showCancelButton:true,confirmButtonText:'Revoke',icon:'warning'});if(!r.isConfirmed)return;try{await this.api.post('/admin/scope-grants/'+row.id+'/revoke',{reason:r.value},row.version);this.toast('Scope grant revoked.');await this.load();}catch(e){this.failed(e);}}
  async setPassword(row:any):Promise<void>{
    const username=await this.alert({title:'Set sign-in username',input:'text',inputLabel:'Username *',inputValidator:v=>v.trim().length<3?'Username required.':null,showCancelButton:true});if(!username.isConfirmed)return;
    const password=await this.alert({title:'Set temporary password',text:'The user must change it at next sign-in. Existing sessions will be revoked.',input:'password',inputLabel:'Temporary password *',inputValidator:v=>v.length<12||v.length>72?'Use 12–72 bytes.':null,showCancelButton:true,confirmButtonText:'Continue'});if(!password.isConfirmed)return;
    const reason=await this.alert({title:'Confirm credential reset',input:'textarea',inputLabel:'Approval reason *',inputValidator:v=>v.trim().length<3?'Reason required.':null,showCancelButton:true,confirmButtonText:'Reset credential',icon:'warning'});if(!reason.isConfirmed)return;
    this.busy=true;try{await this.api.post('/admin/users/'+row.id+'/password',{username:username.value,temporary_password:password.value,reason:reason.value},row.version);this.toast('Temporary credential set. Share it through your approved private channel.');await this.load();}catch(e){this.failed(e);}finally{this.busy=false;}
  }
  async exportReport():Promise<void>{try{await this.api.download('/reports/export',this.status?{status:this.status}:{});this.toast('Authorized CSV downloaded.');}catch(e){this.failed(e);}}
  savePreferences():void{try{new Intl.DateTimeFormat('en',{timeZone:this.timezone});localStorage.setItem('jsc-preferences',JSON.stringify({help:this.help,timezone:this.timezone}));this.toast('Preferences saved.');}catch{this.toast('Enter a valid time zone, for example Africa/Harare.','error');}}

  async newWorkflow(existing?:any):Promise<void>{
    this.modal='workflow';this.modalTitle=existing?'Workflow draft':'New workflow';this.wizardStep=0;this.workflowEditable=existing?.can_design??this.can('workflow.design');this.workflowDiff=null;this.workflow=existing?.status==='draft'?existing:null;
    let saved:any=null;if(!existing){try{saved=JSON.parse(localStorage.getItem(this.draftKey('workflow'))??'null');}catch{}}
    if(!existing&&saved?.workflow?.status==='draft')this.workflow=saved.workflow;
    const definition=existing?.definition??saved?.definition;
    this.defaultCc=definition?.default_cc_department_ids??[];this.workflowCalendar={timezone:'Africa/Harare',days:[1,2,3,4,5],start:'08:00',end:'16:30',holidays:[],...(definition?.calendar??{})};this.workflowHolidays=this.workflowCalendar.holidays.join(', ');
    this.workflowCategory=existing?.category_id??saved?.category_id??'';this.workflowTitle=definition?.category_name??'';this.requireSecond=definition?.require_second_approver??true;
    this.workflowFields=Object.entries(definition?.form_schema?.properties??{}).map(([key,value]:[string,any])=>({key,...value,required:definition.form_schema.required?.includes(key)??false,options:(value.enum??[]).join(', ')}));
    this.nodes=definition?.nodes?structuredClone(definition.nodes):[{id:'start',type:'start',label:'Submitted',config:{}},{id:'work',type:'claim_pool',label:'Triage',config:{department_id:'',position_id:'',scope:'station',sla_minutes:240}},{id:'end',type:'end',label:'Closed',config:{}}];
    this.edges=(definition?.edges??[{from:'start',to:'work'},{from:'work',to:'end'}]).map((edge:any)=>({...edge,variable:edge.when?Object.values(edge.when)[0] instanceof Array?(Object.values(edge.when)[0]as any)[0]?.var:'':'',operator:edge.when?Object.keys(edge.when)[0]:'',value:edge.when?(Object.values(edge.when)[0]as any)?.[1]:''}));
    this.simulation=null;this.showModal();this.references={};await this.loadWorkflowChoices();
  }
  async loadWorkflowChoices():Promise<void>{await Promise.all(['categories','departments','positions'].map(resource=>this.loadWorkflowChoice(resource)));}
  async loadWorkflowChoice(resource:string,more=false):Promise<void>{const key='workflow_'+resource;try{const q:any={q:this.refQueries[key]??''};if(more&&this.refNext[key])q.after=this.refNext[key];const r=await this.api.get('/workflows/choices/'+resource,q);let rows=more?[...(this.references[key]??[]),...r.data]:r.data;const selected=resource==='categories'?[this.workflowCategory]:[...this.nodes.map(n=>n.config[resource==='departments'?'department_id':'position_id']),...(resource==='departments'?this.defaultCc:[])];for(const id of new Set<string>(selected.filter(Boolean))){rows=await this.preserveChoice('/workflows/choices/'+resource,rows,id);}this.references[key]=rows;this.refNext[key]=r.meta.next_after??null;}catch(e){this.failed(e);}}
  addField():void{this.workflowFields.push({key:'field_'+(this.workflowFields.length+1),type:'string',title:'',required:false,options:''});this.rememberWorkflow();}
  addNode():void{this.nodes.splice(Math.max(1,this.nodes.length-1),0,{id:'step_'+Date.now().toString(36),type:'task',label:'New task',config:{department_id:'',position_id:'',scope:'station'}});this.rememberWorkflow();}
  removeNode(id:string):void{this.nodes=this.nodes.filter(n=>n.id!==id);this.edges=this.edges.filter(e=>e.from!==id&&e.to!==id);this.rememberWorkflow();}
  addEdge():void{this.edges.push({from:'',to:'',variable:'',operator:'eq',value:'',otherwise:false});this.rememberWorkflow();}
  workflowDefinition():any{
    const properties:any={},required:string[]=[];
    for(const field of this.workflowFields){if(!field.key)continue;const rule:any={type:field.type};if(field.title)rule.title=field.title;if(field.options&&field.type!=='boolean')rule.enum=field.options.split(',').map((v:string)=>field.type==='string'?v.trim():Number(v.trim()));properties[field.key]=rule;if(field.required)required.push(field.key);}
    const category=(this.references['workflow_categories']??[]).find(c=>c.id===this.workflowCategory);
    return {engine:'workflow-v1',department_id:category?.department_id??'',category_name:this.workflowTitle,require_second_approver:this.requireSecond,
      default_cc_department_ids:this.defaultCc,...(this.workflowCalendar?{calendar:this.workflowCalendar}:{}),form_schema:{type:'object',properties,required,additionalProperties:false},nodes:this.nodes.map(node=>{const config:any={...node.config};for(const key of Object.keys(config)){if(config[key]===''||config[key]===null||config[key]===undefined)delete config[key];}return{id:node.id,type:node.type,label:node.label,config};}),
      edges:this.edges.map(edge=>{const e:any={from:edge.from,to:edge.to};if(edge.otherwise)e.otherwise=true;else if(edge.variable)e.when={[edge.operator||'eq']:[{var:edge.variable},edge.value]};else if(edge.when)e.when=edge.when;return e;})};
  }
  toggleBusinessDay(day:number):void{this.workflowCalendar.days=this.workflowCalendar.days.includes(day)?this.workflowCalendar.days.filter((d:number)=>d!==day):[...this.workflowCalendar.days,day].sort();this.rememberWorkflow();}
  updateHolidays():void{this.workflowCalendar.holidays=this.workflowHolidays.split(',').map(v=>v.trim()).filter(Boolean);this.rememberWorkflow();}
  rememberWorkflow():void{this.simulation=null;this.workflowDiff=null;localStorage.setItem(this.draftKey('workflow'),JSON.stringify({category_id:this.workflowCategory,definition:this.workflowDefinition(),workflow:this.workflow}));}
  async saveWorkflow():Promise<boolean>{if(!this.workflowEditable)return false;this.busy=true;this.rememberWorkflow();try{const r=await this.api.post('/workflows'+(this.workflow?'/'+this.workflow.id:''),{category_id:this.workflowCategory,definition:this.workflowDefinition()},this.workflow?Number(this.workflow.version):undefined,this.workflow?'PUT':'POST');this.workflow=r.data;this.rememberWorkflow();this.toast('Workflow draft saved.');return true;}catch(e){this.failed(e);return false;}finally{this.busy=false;}}
  async simulateWorkflow():Promise<void>{if(this.workflowEditable&&!await this.saveWorkflow())return;if(!this.workflow)return;this.busy=true;try{this.simulation=(await this.api.post('/workflows/'+this.workflow.id+'/simulate',{'ticket.category':this.simCategory,'ticket.priority':this.simPriority,'step.outcome':'approve'})).data;this.workflowDiff=(await this.api.get('/workflows/'+this.workflow.id+'/review')).data;this.toast('Simulation passed.');}catch(e){this.simulation=null;this.failed(e);}finally{this.busy=false;}}
  async publishWorkflow():Promise<void>{if(!this.workflow||!this.simulation){this.toast('Save and simulate the current draft first.','error');return;}const result=await this.alert({title:'Publish this workflow?',text:'New submissions will use this immutable version. Existing tickets retain their version.',input:'textarea',inputLabel:'Approval reason *',inputValidator:v=>v.trim().length<3?'Reason required.':null,showCancelButton:true,confirmButtonText:'Publish',icon:'warning'});if(!result.isConfirmed)return;this.busy=true;try{await this.api.post('/workflows/'+this.workflow.id+'/publish',{reason:result.value},Number(this.workflow.version));localStorage.removeItem(this.draftKey('workflow'));this.dialog?.nativeElement.close();this.modal='';this.toast('Workflow published.');await this.load();}catch(e){this.failed(e);}finally{this.busy=false;}}
}
