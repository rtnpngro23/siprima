/* Shim loader: loads global.js and the real script in user/js/ */
(function(){
  function load(src, cb){ var s=document.createElement('script'); s.src = src; s.onload = cb || function(){}; document.head.appendChild(s); }
  load('js/global.js', function(){ load('js/user.js'); });
})();
