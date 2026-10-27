'use strict';
const CACHE='paleco-hub-static-v1';
const BASE=new URL(self.registration.scope).pathname.replace(/\/$/,'');
const STATIC=['/assets/bootstrap.min.css','/assets/app.css','/assets/app.js','/assets/paleco-logo.png','/assets/icon-192.png','/assets/icon-512.png'].map(p=>BASE+p);
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(STATIC))));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('paleco-hub-static-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
// Exact static allowlist only. Navigations, OAuth, private images, and API responses never enter CacheStorage.
self.addEventListener('fetch',event=>{const u=new URL(event.request.url);if(event.request.method!=='GET'||u.origin!==self.location.origin||u.search||!STATIC.includes(u.pathname))return;event.respondWith(fetch(event.request).then(response=>{if(response.ok){const copy=response.clone();event.waitUntil(caches.open(CACHE).then(cache=>cache.put(event.request,copy)));}return response;}).catch(()=>caches.match(event.request)));});
