# Isolated verification

The suite never loads an existing site's configuration or database. All generated WordPress files, database, synthetic accounts, private files and credentials stay in the ignored .tests/runtime directory. External WordPress HTTP and email are blocked by a QA-only MU plugin. Real cloud responses are simulated at the native HTTP boundary.

Requirements: Python 3.9+, Node 22+, PHP with mysqli, Fileinfo, mbstring, Zip; SQLite runs also need pdo_sqlite/sqlite3. Production has no Node/Python dependency.

```sh
python .tests/prepare-runtime.py
php .tests/test-regressions.php
python .tests/test-concurrency.py
python .tests/test-http.py --serve
node .tests/test-client.cjs
python .tests/check-release.py
```

Use --core-source /absolute/path/to/wordpress to copy only local core code (never its config/database/plugins). On Windows, enable the listed DLLs through php.ini or pass PHP -d extension arguments for the PHP suite. HTTP/concurrency wrappers locate DLLs alongside php.exe. On CI, --backend mysql uses FILEHUB_TEST_DB_HOST/NAME/USER/PASSWORD against a disposable MySQL service.

The old test-real-playground-suite.php entry point delegates to the current suite. The verify workflow covers the declared minimum PHP/WordPress combination and current WordPress on newer PHP. Local results do not claim those remote CI jobs have run.

Scope: actual WordPress REST dispatch, metadata and SQL, filesystem staging, guest/member/admin policies, genuine HTTP cookie auth and multipart upload, native provider HTTP contracts, concurrency and the actual JavaScript asset in a minimal DOM. Real provider accounts, a real ClamAV engine, hosting proxies and full browser/theme behavior require deployment verification.
