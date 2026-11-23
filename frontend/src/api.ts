import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams, HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';

export interface Envelope<T=any> { status:string;code:number;message:string;data:T;meta:any;errors:any; }
@Injectable({providedIn:'root'})
export class Api {
  private http=inject(HttpClient);
  token='';
  private intents=new Map<string,string>();
  async get<T=any>(path:string,query:Record<string,string>={}):Promise<Envelope<T>> {
    return firstValueFrom(this.http.get<Envelope<T>>('/api/v1'+path,{headers:this.headers(),params:new HttpParams({fromObject:query})}));
  }
  async post<T=any>(path:string,body:any={},version?:number,method='POST'):Promise<Envelope<T>> {
    const intent=path+':'+JSON.stringify(body)+':'+version;
    let key=this.intents.get(intent);if(!key){key=crypto.randomUUID();this.intents.set(intent,key);}
    let headers=this.headers().set('Idempotency-Key',key);
    if(version!==undefined){headers=headers.set('If-Match','"'+version+'"');}
    try{
      const result=await firstValueFrom(this.http.request<Envelope<T>>(method,'/api/v1'+path,{headers,body}));
      this.intents.delete(intent);return result;
    }catch(error){if(error instanceof HttpErrorResponse&&error.status!==0){this.intents.delete(intent);}throw error;}
  }
  async upload(path:string,file:File,classification:string,version:number):Promise<Envelope>{
    const digest=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await file.arrayBuffer()))).map(v=>v.toString(16).padStart(2,'0')).join('');
    const intent=path+':'+file.name+':'+digest+':'+classification+':'+version;
    let key=this.intents.get(intent);if(!key){key=crypto.randomUUID();this.intents.set(intent,key);}
    const body=new FormData();body.append('file',file);body.append('classification',classification);
    try{const result=await firstValueFrom(this.http.post<Envelope>('/api/v1'+path,body,{headers:this.headers().set('Idempotency-Key',key).set('If-Match','"'+version+'"')}));this.intents.delete(intent);return result;}
    catch(error){if(error instanceof HttpErrorResponse&&error.status!==0)this.intents.delete(intent);throw error;}
  }
  async downloadFile(id:string,name:string):Promise<void>{
    const blob=await firstValueFrom(this.http.get('/api/v1/attachments/'+id+'/download',{headers:this.headers(),responseType:'blob'}));
    const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  async download(path:string,query:Record<string,string>={}):Promise<void>{
    const blob=await firstValueFrom(this.http.get('/api/v1'+path,{headers:this.headers(),params:query,responseType:'blob'}));
    const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=query['format']==='pdf'?'jsc-tickets.pdf':'jsc-tickets.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  clear():void{this.token='';this.intents.clear();}
  private headers():HttpHeaders{return new HttpHeaders(this.token?{Authorization:'Bearer '+this.token}:{});}
}
