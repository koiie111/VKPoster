// The trusted local spec is the only input. Do not send documentation usage to an external telemetry service.
import {init} from '../vendor/redoc.standalone.js?v=3.0.1-local1';
init('/api/openapi.yaml', {disableTelemetry: true}, document.getElementById('api-reference'));
