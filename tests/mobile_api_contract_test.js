const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const spec = JSON.parse(fs.readFileSync(path.join(__dirname,'../docs/mobile-api.openapi.json'),'utf8'));
function resolve(ref) { return ref.slice(2).split('/').reduce((v,k)=>v[k],spec); }
function validate(value,schema,at='$') {
  if(schema.$ref)return validate(value,resolve(schema.$ref),at);
  if(value===null&&schema.nullable)return;
  if(schema.allOf)for(const part of schema.allOf)validate(value,part,at);
  if(schema.oneOf) {let matches=0;for(const part of schema.oneOf){try{validate(value,part,at);matches++;}catch{}}assert.equal(matches,1,at+' oneOf');}
  if(schema.type==='object') {
    assert.ok(value!==null&&typeof value==='object'&&!Array.isArray(value),at+' object');
    for(const key of schema.required||[])assert.ok(Object.hasOwn(value,key),at+'.'+key+' required');
    for(const [k,v] of Object.entries(value)) {
      if(schema.properties?.[k])validate(v,schema.properties[k],at+'.'+k);
      else if(schema.additionalProperties===false)assert.fail(at+'.'+k+' unexpected');
      else if(typeof schema.additionalProperties==='object')validate(v,schema.additionalProperties,at+'.'+k);
    }
  }
  if(schema.type==='array') {assert.ok(Array.isArray(value),at+' array');assert.ok(value.length>=(schema.minItems||0),at+' minItems');assert.ok(value.length<=(schema.maxItems??Infinity),at+' maxItems');for(const [i,v] of value.entries())validate(v,schema.items,at+'['+i+']');}
  if(schema.type==='string') {assert.equal(typeof value,'string',at+' string');if(schema.pattern)assert.match(value,new RegExp(schema.pattern),at);if(schema.maxLength)assert.ok([...value].length<=schema.maxLength,at+' maxLength');}
  if(schema.type==='integer') {assert.ok(Number.isInteger(value),at+' integer');if(schema.minimum!==undefined)assert.ok(value>=schema.minimum,at+' minimum');if(schema.maximum!==undefined)assert.ok(value<=schema.maximum,at+' maximum');}
  if(schema.type==='boolean')assert.equal(typeof value,'boolean',at+' boolean');
  if(schema.enum)assert.ok(schema.enum.includes(value),at+' enum');
}
function validateResponse(url,method,status,value) {
  const pathname=url.split('?')[0];
  const match=Object.entries(spec.paths).find(([p])=>new RegExp('^'+p.replace(/\{[^}]+\}/g,'[^/]+')+'$').test(pathname));
  assert.ok(match,'Documented path '+pathname);
  const operation=match[1][method.toLowerCase()];assert.ok(operation,'Documented method '+method);
  const response=operation.responses[String(status)];assert.ok(response,'Documented status '+status);
  validate(value,response.content['application/json'].schema);
}
function checkDocument() {
  assert.equal(spec.openapi,'3.0.3');let operations=0;
  function walk(v) {if(!v||typeof v!=='object')return;if(v.$ref)assert.ok(resolve(v.$ref),v.$ref);if(Array.isArray(v.required))assert.ok(v.required.length>0,'required cannot be empty');for(const x of Object.values(v))walk(x);}
  walk(spec);
  const ids=new Set();
  for(const [p,item] of Object.entries(spec.paths))for(const [method,op] of Object.entries(item)) {
    operations++;assert.ok(!ids.has(op.operationId));ids.add(op.operationId);
    for(const name of [...p.matchAll(/\{([^}]+)\}/g)].map(m=>m[1]))assert.ok(op.parameters.some(x=>x.name===name&&x.in==='path'&&x.required));
    if(['post','patch'].includes(method))assert.ok(op.requestBody,p+' body');
    assert.ok(op.responses['503']);
  }
  assert.equal(operations,30);
  console.log('PASS OpenAPI local references, path parameters, operation IDs and 30 operations');
}
if(require.main===module)checkDocument();
module.exports={validateResponse,checkDocument};
