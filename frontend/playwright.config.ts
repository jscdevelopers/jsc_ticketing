import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'./tests',timeout:60000,expect:{timeout:15000},fullyParallel:false,workers:1,retries:0,
  reporter:[['list']],use:{baseURL:process.env['E2E_BASE_URL']??'http://127.0.0.1:8180',channel:'chrome',headless:true,trace:'retain-on-failure',screenshot:'only-on-failure'},
  projects:[{name:'desktop',use:{viewport:{width:1440,height:1000}}},{name:'mobile',use:{viewport:{width:320,height:812}}}]});
