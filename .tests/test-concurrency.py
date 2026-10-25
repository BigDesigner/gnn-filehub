import json
import os
import pathlib
import shutil
import subprocess
import time

root = pathlib.Path(__file__).resolve().parent
runtime = root / 'runtime'
php = shutil.which('php')
command = [php]
if os.name == 'nt':
    command += ['-d', 'extension_dir=' + (pathlib.Path(php).parent / 'ext').as_posix()]
    for extension in ['fileinfo', 'pdo_sqlite', 'sqlite3', 'mysqli', 'zip', 'mbstring']:
        command += ['-d', 'extension=php_' + extension + '.dll']
command += [str(root / 'concurrency-worker.php')]
subprocess.run(command + ['prepare'], check=True, capture_output=True)
for name in ['race-start', 'race-ready-1', 'race-ready-2']:
    (runtime / name).unlink(missing_ok=True)
workers = [subprocess.Popen(command + ['reserve', str(number)], stdout=subprocess.PIPE, stderr=subprocess.PIPE) for number in (1, 2)]
try:
    for attempt in range(100):
        if all((runtime / ('race-ready-' + str(number))).is_file() for number in (1, 2)):
            break
        time.sleep(0.05)
    else:
        raise RuntimeError('Workers did not reach the start barrier')
    (runtime / 'race-start').write_text('start')
    results = []
    for worker in workers:
        output, errors = worker.communicate(timeout=30)
        assert worker.returncode == 0, errors.decode(errors='replace')
        results.append(json.loads(output))
    statuses = sorted(row['status'] for row in results)
    assert statuses[0] == 200 and statuses[1] in [409, 413], results
    inspected = json.loads(subprocess.check_output(command + ['inspect']))
    assert inspected['reserved'] == 700000, inspected
    report = {'mode': 'two concurrent PHP processes, real WordPress and database', 'passed': 2, 'statuses': statuses, 'reserved': inspected['reserved']}
    (runtime / 'concurrency-results.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report, indent=2))
finally:
    for worker in workers:
        if worker.poll() is None:
            worker.terminate()
            worker.wait(timeout=10)
    for name in ['race-start', 'race-ready-1', 'race-ready-2']:
        (runtime / name).unlink(missing_ok=True)
