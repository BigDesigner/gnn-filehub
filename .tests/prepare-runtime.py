"""Prepare an isolated WordPress QA runtime; never read a production config or database."""
import argparse
import pathlib
import secrets
import shutil
import urllib.request
import zipfile

repo = pathlib.Path(__file__).resolve().parents[1]
runtime = repo / '.tests' / 'runtime'
parser = argparse.ArgumentParser()
parser.add_argument('--core-source', type=pathlib.Path)
parser.add_argument('--wordpress', default='latest')
parser.add_argument('--backend', choices=['sqlite', 'mysql'], default='sqlite')
args = parser.parse_args()
runtime.mkdir(exist_ok=True)
wp = runtime / 'wordpress'
wp.mkdir(exist_ok=True)


def download(url, destination):
    with urllib.request.urlopen(url, timeout=90) as response, destination.open('wb') as output:
        shutil.copyfileobj(response, output)


def extract(archive, destination):
    with zipfile.ZipFile(archive) as source:
        for item in source.infolist():
            target = (destination / item.filename).resolve()
            if not target.is_relative_to(destination.resolve()):
                raise RuntimeError('Unsafe archive entry')
        source.extractall(destination)


if args.core_source:
    source = args.core_source.resolve()
    if not (source / 'wp-settings.php').is_file():
        raise RuntimeError('Not a WordPress core directory')
    for name in ['wp-admin', 'wp-includes']:
        shutil.copytree(source / name, wp / name, dirs_exist_ok=True)
    for item in source.glob('*.php'):
        if item.name != 'wp-config.php':
            shutil.copy2(item, wp / item.name)
else:
    import re
    if args.wordpress != 'latest' and not re.fullmatch(r'\d+\.\d+(?:\.\d+)?', args.wordpress):
        raise RuntimeError('Invalid WordPress version')
    url = 'https://wordpress.org/latest.zip' if args.wordpress == 'latest' else f'https://wordpress.org/wordpress-{args.wordpress}.zip'
    archive = runtime / 'core.zip'
    download(url, archive)
    extract(archive, runtime)

content = wp / 'wp-content'
(content / 'mu-plugins').mkdir(parents=True, exist_ok=True)
(content / 'plugins').mkdir(exist_ok=True)
if args.backend == 'sqlite':
    archive = runtime / 'sqlite.zip'
    if not archive.is_file():
        download('https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip', archive)
    extract(archive, runtime / 'sqlite')
    implementation = runtime / 'sqlite' / 'sqlite-database-integration'
    drop = (implementation / 'db.copy').read_text(encoding='utf-8')
    (content / 'db.php').write_text(drop.replace('{SQLITE_IMPLEMENTATION_FOLDER_PATH}', implementation.as_posix()), encoding='utf-8')
else:
    (content / 'db.php').unlink(missing_ok=True)

target = content / 'plugins' / 'gnn-filehub'
if not target.exists():
    try:
        target.symlink_to(repo / 'gnn-filehub', target_is_directory=True)
    except OSError:
        shutil.copytree(repo / 'gnn-filehub', target)

if target.exists() and not target.is_symlink():
    shutil.copytree(repo / 'gnn-filehub', target, dirs_exist_ok=True)

config = "<?php\n"
if args.backend == 'sqlite':
    config += "define('DB_ENGINE','sqlite'); define('DB_PATH',dirname(__DIR__).'/test.sqlite');\n"
config += "define('DB_NAME',getenv('FILEHUB_TEST_DB_NAME')?:'filehub_test'); define('DB_USER',getenv('FILEHUB_TEST_DB_USER')?:'root'); define('DB_PASSWORD',getenv('FILEHUB_TEST_DB_PASSWORD')?:'synthetic'); define('DB_HOST',getenv('FILEHUB_TEST_DB_HOST')?:'127.0.0.1');\n"
config += "define('WP_DEBUG',true); define('WP_DEBUG_DISPLAY',false); define('WP_DEBUG_LOG',dirname(__DIR__).'/debug.log'); define('DISABLE_WP_CRON',true);\n"
config += "define('WP_HOME','http://127.0.0.1:9461'); define('WP_SITEURL','http://127.0.0.1:9461'); define('GNN_FILEHUB_PRIVATE_DIR',dirname(__DIR__).'/private');\n"
for key in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']:
    config += f"define('{key}','{secrets.token_hex(32)}');\n"
config += "$table_prefix='fhqa_'; if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/'); require_once ABSPATH.'wp-settings.php';\n"
(wp / 'wp-config.php').write_text(config, encoding='utf-8')
(content / 'mu-plugins' / 'filehub-qa.php').write_text("<?php\nadd_filter('pre_http_request',function($pre,$args,$url){return new WP_Error('qa_network_disabled','External network disabled in isolated QA');},1,3);\nadd_filter('pre_wp_mail','__return_true');\n", encoding='utf-8')
theme = content / 'themes' / 'filehub-qa'
theme.mkdir(parents=True, exist_ok=True)
(theme / 'style.css').write_text('/*\nTheme Name: FileHub isolated QA\n*/\n', encoding='utf-8')
(theme / 'index.php').write_text('<!doctype html><html><head><?php wp_head(); ?></head><body><?php while(have_posts()){the_post();the_content();} wp_footer(); ?></body></html>', encoding='utf-8')
print('Isolated WordPress core/config/QA guards ready:', wp)
