import { bootstrapApplication } from '@angular/platform-browser';
import { provideHttpClient } from '@angular/common/http';
import { App } from './app';
bootstrapApplication(App,{providers:[provideHttpClient()]}).catch(()=>{document.body.textContent='Application could not start. Reload or contact your administrator.';});
