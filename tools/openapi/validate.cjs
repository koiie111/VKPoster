// Developer/CI-only validator. Resolution is confined to this trusted, checked-in local specification.
const SwaggerParser = require('@apidevtools/swagger-parser');
const path = require('node:path');
SwaggerParser.validate(path.resolve(__dirname, '../../public/api/openapi.yaml'), {
  resolve: {external: false},
}).then((api) => {
  if (api.openapi !== '3.1.0') throw new Error('Expected OpenAPI 3.1.0');
  console.log(`OpenAPI ${api.openapi} validated: ${Object.keys(api.paths).length} paths`);
}).catch((error) => { console.error(error.message); process.exitCode = 1; });
