<!DOCTYPE html>
<html>
  <head>
    <title>Reputation AI - API Documentation</title>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@3/swagger-ui.css">
    <style>
      html {
        box-sizing: border-box;
        overflow: -moz-scrollbars-vertical;
        overflow-y: scroll;
      }
      *,
      *:before,
      *:after {
        box-sizing: inherit;
      }
      body {
        margin:0;
        background: #fafafa;
        font-family: sans-serif;
      }
      .swagger-ui {
        max-width: 100%;
      }
      .topbar {
        background-color: #1e3a8a !important;
      }
      .swagger-ui .btn {
        background: #1e3a8a;
      }
      .swagger-ui .btn:hover {
        background: #1e40af;
      }
    </style>
  </head>
  <body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@3/swagger-ui.js"></script>
    <script>
      window.onload = function() {
        console.log('Loading Swagger UI with spec from: /api/docs/spec');
        SwaggerUIBundle({
          url: "/api/docs/spec",
          dom_id: '#swagger-ui',
          deepLinking: true,
          presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIBundle.SwaggerUIStandalonePreset
          ],
          plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
          ],
          layout: "StandaloneLayout"
        });
      };
    </script>
  </body>
</html>
