document.addEventListener('DOMContentLoaded',()=>{
 const nav=document.querySelector('.portal-school-bottom');if(!nav)return;
 const groups=[...nav.querySelectorAll('details')];
 groups.forEach(group=>group.addEventListener('toggle',()=>{if(group.open)groups.forEach(other=>{if(other!==group)other.open=false;});}));
 document.addEventListener('click',e=>{if(!nav.contains(e.target))groups.forEach(group=>group.open=false);});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'){const open=groups.find(group=>group.open);if(open){open.open=false;open.querySelector('summary').focus();}}});
});