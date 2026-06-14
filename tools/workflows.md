# Tools Workflows

Start the PHP server from the repository root, then call:

```powershell
php tools/sign_request.php getCapabilities http://127.0.0.1:18080/api.php
```

Run the full local suite:

```powershell
php tools/run_conformance.php http://127.0.0.1:18080/api.php
```

Run a mock marketplace in a second terminal:

```powershell
php -S 127.0.0.1:18081 tools/mock_marketplace.php
```
