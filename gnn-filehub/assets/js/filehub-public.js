/* GNN Filehub - Zero Dependency Native Public Drag & Drop, Manager, Password & Register JS */

/*
 * Live theming: measures the page's actual rendered background color and toggles
 * .filehub-theme-dark on every .filehub-container so our cards always match the theme's
 * CURRENT state — whatever mechanism the theme uses to switch light/dark (OS preference,
 * a manual toggle button, a class or data-attribute on <html>/<body>) — instead of guessing
 * from a single signal like prefers-color-scheme, which can disagree with what the page is
 * actually showing.
 */
(function () {
  function relativeLuminance(rgbString) {
    var m = rgbString && rgbString.match(/[\d.]+/g);
    if (!m || m.length < 3) return 1; // assume light if we can't tell
    var r = Number(m[0]), g = Number(m[1]), b = Number(m[2]);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  }

  function getEffectiveBackgroundColor(startEl) {
    var el = startEl;
    while (el && el !== document.documentElement) {
      var bg = getComputedStyle(el).backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
        return bg;
      }
      el = el.parentElement;
    }
    var htmlBg = getComputedStyle(document.documentElement).backgroundColor;
    if (htmlBg && htmlBg !== 'rgba(0, 0, 0, 0)') return htmlBg;
    return getComputedStyle(document.body).backgroundColor;
  }

  // Themes that explicitly mark their mode (data-theme="dark", a "dark"/"light" class, or the
  // color-scheme CSS property) are trusted first, since that flips the instant a toggle button
  // is clicked. Measuring the rendered background is only a fallback for themes that don't
  // expose any such signal — on some sites the visual background can lag behind the toggle by
  // a beat (transitions, caching/CSS-combination plugins reordering rules, etc.), so preferring
  // the theme's own explicit signal is more reliable than inferring from paint state.
  function detectExplicitThemeSignal() {
    var html = document.documentElement;
    var body = document.body;

    var htmlTheme = (html.getAttribute('data-theme') || '').toLowerCase();
    var bodyTheme = (body.getAttribute('data-theme') || '').toLowerCase();
    var dt = htmlTheme || bodyTheme;
    if (dt) {
      if (dt.indexOf('dark') !== -1) return 'dark';
      if (dt.indexOf('light') !== -1) return 'light';
    }

    if (html.classList.contains('dark') || body.classList.contains('dark') || body.classList.contains('dark-mode')) {
      return 'dark';
    }
    if (html.classList.contains('light') || body.classList.contains('light') || body.classList.contains('light-mode')) {
      return 'light';
    }

    var colorScheme = getComputedStyle(html).colorScheme;
    if (colorScheme === 'dark') return 'dark';
    if (colorScheme === 'light') return 'light';

    return null;
  }

  function applyFilehubTheming() {
    var containers = document.querySelectorAll('.filehub-container');
    if (!containers.length) return;

    var explicit = detectExplicitThemeSignal();

    containers.forEach(function (container) {
      var isDark;
      if (explicit) {
        isDark = explicit === 'dark';
      } else {
        var bg = getEffectiveBackgroundColor(container.parentElement || document.body);
        isDark = relativeLuminance(bg) < 0.5;
      }
      container.classList.toggle('filehub-theme-dark', isDark);
    });
  }

  function init() {
    applyFilehubTheming();

    if (typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(function () {
        applyFilehubTheming();
      });
      observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
      observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
    }

    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) {
        mq.addEventListener('change', applyFilehubTheming);
      } else if (mq.addListener) {
        mq.addListener(applyFilehubTheming);
      }
    }

    // Belt-and-suspenders: some toggles change styling through mechanisms our observer can't
    // see (a swapped stylesheet, a framework re-render). Re-checking every couple of seconds
    // guarantees we self-correct quickly regardless of the theme's exact mechanism.
    setInterval( applyFilehubTheming, 2000 );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

document.addEventListener('DOMContentLoaded', function () {
  const dropZone = document.getElementById('filehub-dropzone');
  const fileInput = document.getElementById('filehub-file-input');
  const progressBar = document.getElementById('filehub-progress-bar');
  const progressFill = document.getElementById('filehub-progress-fill');
  const statusText = document.getElementById('filehub-status-text');
  const passwordForm = document.getElementById('filehub-password-form');
  const passwordStatus = document.getElementById('filehub-password-status');
  const registerForm = document.getElementById('filehub-register-form');
  const registerStatus = document.getElementById('filehub-register-status');

  // Cache of the last fetched (unfiltered) rows per file-list container, for live search.
  const fileListCache = new WeakMap();

  // Add a show/hide toggle to every password field inside FileHub forms — covers the
  // native wp_login_form() password input as well as our own register/password-change fields.
  document.querySelectorAll('.filehub-container input[type="password"]').forEach(function (input) {
    const wrapper = document.createElement('div');
    wrapper.className = 'filehub-password-wrap';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'filehub-password-toggle';
    toggle.setAttribute('aria-label', 'Şifreyi göster/gizle');
    toggle.textContent = '👁';
    wrapper.appendChild(toggle);

    toggle.addEventListener('click', function () {
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      toggle.textContent = showing ? '👁' : '🙈';
    });
  });

  // Drag & Drop File Upload
  if (dropZone && fileInput) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
      e.preventDefault();
      e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
      dropZone.addEventListener(eventName, function () {
        dropZone.classList.add('filehub-highlight');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, function () {
        dropZone.classList.remove('filehub-highlight');
      });
    });

    dropZone.addEventListener('drop', handleDrop, false);
    fileInput.addEventListener('change', function () {
      if (this.files.length > 0) {
        uploadFileQueue(this.files);
      }
    });

    function handleDrop(e) {
      const dt = e.dataTransfer;
      const files = dt.files;
      if (files.length > 0) {
        uploadFileQueue(files);
      }
    }

    const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB chunks — fewer round-trips than 2MB without
                                         // risking common low upload_max_filesize limits
    const CHUNK_CONCURRENCY = 3; // upload several chunks at once to hide per-request
                                  // WordPress bootstrap overhead behind network latency

    // Uploads every selected/dropped file one after another (sequential, not parallel),
    // so the server and the progress bar only ever deal with one upload at a time.
    // Each upload path reports back whether it actually succeeded via onSettled(success) —
    // the final summary reflects real outcomes instead of always claiming success regardless
    // of what happened.
    function uploadFileQueue(fileList) {
      const files = Array.from(fileList);
      let index = 0;
      let failCount = 0;

      if (progressBar) progressBar.style.display = 'block';

      function finish() {
        const successCount = files.length - failCount;

        if (statusText) {
          if (files.length === 1) {
            // Leave whatever specific message the single upload path already set (its own
            // success line, or the real error) instead of overwriting it with a generic one.
            if (failCount === 0) {
              statusText.textContent = 'Yükleme başarıyla tamamlandı!';
            }
          } else if (failCount === 0) {
            statusText.textContent = `${files.length} dosya başarıyla yüklendi!`;
          } else if (successCount === 0) {
            statusText.textContent = `${files.length} dosyanın hiçbiri yüklenemedi.`;
          } else {
            statusText.textContent = `${successCount}/${files.length} dosya yüklendi, ${failCount} dosya başarısız oldu.`;
          }
        }

        setTimeout(() => {
          if (progressBar) progressBar.style.display = 'none';
          refreshAllFileLists();
        }, 1200);
      }

      function uploadNext() {
        if (index >= files.length) {
          finish();
          return;
        }

        const file = files[index];
        const position = ++index; // 1-based for display
        const label = files.length > 1 ? `(${position}/${files.length}) ${file.name}: ` : '';

        const onSettled = function ( success ) {
          if ( false === success ) {
            failCount++;
          }
          uploadNext();
        };

        if (window.filehub_vars && filehub_vars.active_driver === 'r2') {
          // Cloudflare R2 is configured: skip our own server entirely and upload straight from
          // the browser to R2 with a presigned URL — no PHP memory/time limits apply, any file
          // size behaves the same way (this is how WeTransfer-style uploads actually work).
          uploadFileR2Direct(file, label, onSettled);
        } else if (file.size > CHUNK_SIZE) {
          uploadFileChunked(file, label, onSettled);
        } else {
          uploadFileSingle(file, label, onSettled);
        }
      }

      uploadNext();
    }

    function uploadFileR2Direct(file, label, onSettled) {
      if (statusText) statusText.textContent = label + 'Yükleme başlatılıyor...';

      fetch(filehub_vars.rest_url + 'filehub/v1/r2-presign', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': filehub_vars.nonce
        },
        body: JSON.stringify({ filename: file.name, file_size: file.size })
      })
        .then(res => res.json())
        .then(presignData => {
          if (!presignData.success) {
            if (statusText) statusText.textContent = label + 'Hata: ' + (presignData.error || 'Yükleme başlatılamadı.');
            onSettled(false);
            return;
          }

          const xhr = new XMLHttpRequest();
          const startTime = new Date().getTime();

          xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
              const percent = Math.round((e.loaded / e.total) * 100);
              if (progressFill) progressFill.style.width = percent + '%';

              if (percent >= 100) {
                // All bytes are with R2 now, but we still have to tell WordPress about it —
                // don't leave the user staring at a frozen "%100" with no explanation.
                if (statusText) statusText.textContent = label + 'Dosya işleniyor, lütfen bekleyin...';
              } else {
                const elapsedTime = (new Date().getTime() - startTime) / 1000;
                const speedBytes = elapsedTime > 0 ? e.loaded / elapsedTime : 0;
                const speedMB = (speedBytes / (1024 * 1024)).toFixed(2);
                if (statusText) statusText.textContent = `${label}Yükleniyor: %${percent} (${speedMB} MB/s)`;
              }
            }
          });

          xhr.onreadystatechange = function () {
            if (xhr.readyState === XMLHttpRequest.DONE) {
              if (xhr.status >= 200 && xhr.status < 300) {
                if (statusText) statusText.textContent = label + 'Kaydediliyor...';

                fetch(filehub_vars.rest_url + 'filehub/v1/r2-finalize', {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': filehub_vars.nonce
                  },
                  body: JSON.stringify({ key: presignData.key, filename: file.name, mime_type: file.type })
                })
                  .then(res => res.json())
                  .then(finalizeData => {
                    if (finalizeData.success) {
                      if (statusText) statusText.textContent = label + 'Yükleme başarıyla tamamlandı!';
                      if (progressFill) progressFill.style.width = '100%';
                      onSettled(true);
                    } else {
                      if (statusText) statusText.textContent = label + 'Hata: ' + (finalizeData.error || 'Kayıt başarısız.');
                      onSettled(false);
                    }
                  })
                  .catch(() => {
                    if (statusText) statusText.textContent = label + 'Sunucu bağlantı hatası.';
                    onSettled(false);
                  });
              } else {
                if (statusText) statusText.textContent = label + 'Hata: Cloudflare R2\'ye yükleme başarısız. Bucket CORS ayarlarını kontrol edin.';
                onSettled(false);
              }
            }
          };

          xhr.open('PUT', presignData.upload_url, true);
          xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
          xhr.send(file);
        })
        .catch(() => {
          if (statusText) statusText.textContent = label + 'Sunucu bağlantı hatası.';
          onSettled(false);
        });
    }

    function uploadFileSingle(file, label, onSettled) {
      const formData = new FormData();
      formData.append('file', file);

      const xhr = new XMLHttpRequest();
      const startTime = new Date().getTime();

      if (statusText) statusText.textContent = label + 'Yükleme başlatılıyor...';

      xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable) {
          const percent = Math.round((e.loaded / e.total) * 100);
          if (progressFill) progressFill.style.width = percent + '%';

          if (percent >= 100) {
            // The bytes are all uploaded, but the server still has to save/relay the file —
            // don't leave the user staring at a frozen "%100" with no explanation.
            if (statusText) statusText.textContent = label + 'Dosya işleniyor, lütfen bekleyin...';
          } else {
            const elapsedTime = (new Date().getTime() - startTime) / 1000;
            const speedBytes = elapsedTime > 0 ? e.loaded / elapsedTime : 0;
            const speedMB = (speedBytes / (1024 * 1024)).toFixed(2);
            if (statusText) statusText.textContent = `${label}Yükleniyor: %${percent} (${speedMB} MB/s)`;
          }
        }
      });

      xhr.onreadystatechange = function () {
        if (xhr.readyState === XMLHttpRequest.DONE) {
          if (xhr.status === 200) {
            if (statusText) statusText.textContent = label + 'Yükleme başarıyla tamamlandı!';
            if (progressFill) progressFill.style.width = '100%';
            onSettled(true);
          } else {
            try {
              const resp = JSON.parse(xhr.responseText);
              if (statusText) statusText.textContent = label + 'Hata: ' + (resp.error || 'Yükleme başarısız.');
            } catch (err) {
              if (statusText) statusText.textContent = label + 'Sunucu yükleme hatası.';
            }
            onSettled(false);
          }
        }
      };

      xhr.open('POST', filehub_vars.rest_url + 'filehub/v1/upload', true);
      xhr.setRequestHeader('X-WP-Nonce', filehub_vars.nonce);
      xhr.send(formData);
    }

    // Uploads a large file's chunks with limited concurrency instead of one-at-a-time:
    // each chunk is still its own request (server assembles them by index once all have
    // arrived), but sending several in parallel hides most of the per-request WordPress
    // bootstrap/auth overhead behind network latency instead of paying it chunk-by-chunk.
    function uploadFileChunked(file, label, onSettled) {
      const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
      const fileId = 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
      const startTime = new Date().getTime();
      const chunkLoadedBytes = new Array(totalChunks).fill(0);

      let nextChunkIndex = 0;
      let failed = false;
      let failMessage = '';

      if (statusText) {
        statusText.textContent = `${label}Parçalı yükleme başlatılıyor (Toplam ${totalChunks} parça)...`;
      }

      function totalLoaded() {
        return chunkLoadedBytes.reduce((sum, n) => sum + n, 0);
      }

      function updateProgress() {
        const loaded = totalLoaded();
        const percent = Math.min(100, Math.round((loaded / file.size) * 100));

        if (progressFill) progressFill.style.width = percent + '%';

        if (percent >= 100) {
          // Every chunk has reached the server, but it still has to merge them and hand the
          // result off to the storage driver (this is the slow part for a cloud destination) —
          // without this message the bar just sits at "%100" with no explanation, which reads
          // as "frozen" and tempts people to refresh mid-upload.
          if (statusText) {
            statusText.textContent = label + 'Dosya işleniyor, lütfen bekleyin... Sayfayı kapatmayın veya yenilemeyin.';
          }
        } else {
          const elapsedTime = (new Date().getTime() - startTime) / 1000;
          const speedBytes = elapsedTime > 0 ? loaded / elapsedTime : 0;
          const speedMB = (speedBytes / (1024 * 1024)).toFixed(2);
          if (statusText) statusText.textContent = `${label}Yükleniyor: %${percent} (${speedMB} MB/s)`;
        }
      }

      function sendChunk(chunkIndex) {
        return new Promise(function (resolve) {
          const start = chunkIndex * CHUNK_SIZE;
          const end = Math.min(start + CHUNK_SIZE, file.size);
          const chunkBlob = file.slice(start, end);

          const formData = new FormData();
          formData.append('chunk', chunkBlob, file.name);
          formData.append('file_id', fileId);
          formData.append('chunk_index', chunkIndex);
          formData.append('total_chunks', totalChunks);
          formData.append('filename', file.name);

          const xhr = new XMLHttpRequest();

          xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
              chunkLoadedBytes[chunkIndex] = e.loaded;
              updateProgress();
            }
          });

          xhr.onreadystatechange = function () {
            if (xhr.readyState === XMLHttpRequest.DONE) {
              if (xhr.status === 200) {
                chunkLoadedBytes[chunkIndex] = end - start;
                updateProgress();
              } else if (!failed) {
                failed = true;
                try {
                  const resp = JSON.parse(xhr.responseText);
                  failMessage = resp.error || 'Parça yükleme başarısız.';
                } catch (err) {
                  failMessage = `Parça ${chunkIndex + 1} yüklenirken sunucu hatası oluştu.`;
                }
              }
              resolve();
            }
          };

          xhr.open('POST', filehub_vars.rest_url + 'filehub/v1/upload-chunk', true);
          xhr.setRequestHeader('X-WP-Nonce', filehub_vars.nonce);
          xhr.send(formData);
        });
      }

      function worker() {
        if (nextChunkIndex >= totalChunks) {
          return Promise.resolve();
        }
        const chunkIndex = nextChunkIndex++;
        return sendChunk(chunkIndex).then(worker);
      }

      const workerCount = Math.min(CHUNK_CONCURRENCY, totalChunks);
      const workers = [];
      for (let i = 0; i < workerCount; i++) {
        workers.push(worker());
      }

      Promise.all(workers).then(function () {
        if (failed) {
          if (statusText) statusText.textContent = label + 'Hata: ' + failMessage;
          onSettled(false);
        } else {
          if (statusText) statusText.textContent = label + 'Tüm parçalar birleştirildi, yükleme başarılı!';
          if (progressFill) progressFill.style.width = '100%';
          onSettled(true);
        }
      });
    }
  }

  // File Manager & Live Search — supports any number of file-list blocks on the same page
  // (e.g. the uploader shortcode shows the user's own files right below the dropzone).
  function fetchFileList(container) {
    if (!container) return;
    const scope = container.getAttribute('data-scope') || 'own';
    fetch(filehub_vars.rest_url + 'filehub/v1/files?scope=' + encodeURIComponent(scope), {
      headers: {
        'X-WP-Nonce': filehub_vars.nonce
      }
    })
      .then(res => res.json())
      .then(data => {
        if (!Array.isArray(data)) return;
        fileListCache.set(container, data);
        renderFileList(container, data);
      })
      .catch(err => console.error(err));
  }

  function refreshAllFileLists() {
    document.querySelectorAll('.filehub-file-list').forEach(fetchFileList);
  }

  function renderFileList(container, items) {
    if (!container) return;
    let html = '<div class="filehub-table-wrap"><table class="filehub-table"><thead><tr><th>Dosya Adı</th><th>Boyut</th><th>Sürücü</th><th>Yükleyen</th><th>İndirme</th><th>İşlem</th></tr></thead><tbody>';
    if (items.length === 0) {
      html += '<tr><td colspan="6" style="text-align:center; padding: 20px;">Dosya bulunamadı.</td></tr>';
    } else {
      items.forEach(item => {
        const deleteBtn = item.can_delete ? `<button type="button" class="button button-link-delete button-small filehub-delete-btn" data-id="${item.id}" style="color:#b32d2e; margin-left:8px;">Sil</button>` : '';
        html += `<tr>
          <td><strong>${escapeHtml(item.file_name || item.title)}</strong></td>
          <td>${item.file_size}</td>
          <td><span class="filehub-driver-badge">${item.driver}</span></td>
          <td>${escapeHtml(item.author_name)}</td>
          <td>${item.download_count}</td>
          <td>
            <a href="${item.download_url}" class="button button-secondary button-small" target="_blank">İndir</a>
            ${deleteBtn}
          </td>
        </tr>`;
      });
    }
    html += '</tbody></table></div>';
    container.innerHTML = html;

    // Attach Delete Event Listeners (scoped to this container only)
    container.querySelectorAll('.filehub-delete-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        const fileId = this.getAttribute('data-id');
        if (confirm('Bu dosyayı kalıcı olarak silmek istediğinizden emin misiniz?')) {
          deleteFile(fileId, container);
        }
      });
    });
  }

  function deleteFile(fileId, container) {
    fetch(filehub_vars.rest_url + 'filehub/v1/files/' + fileId, {
      method: 'DELETE',
      headers: {
        'X-WP-Nonce': filehub_vars.nonce
      }
    })
      .then(res => res.json())
      .then(resp => {
        if (resp.success) {
          fetchFileList(container);
        } else {
          alert(resp.error || 'Silme işlemi başarısız.');
        }
      })
      .catch(err => alert('Sunucu bağlantı hatası.'));
  }

  document.querySelectorAll('.filehub-search-input').forEach(searchInput => {
    searchInput.addEventListener('input', function () {
      const scope = searchInput.closest('.filehub-uploader, .filehub-manager');
      const container = scope ? scope.querySelector('.filehub-file-list') : null;
      if (!container) return;

      const term = this.value.toLowerCase().trim();
      const cached = fileListCache.get(container) || [];
      const filtered = cached.filter(item =>
        (item.file_name || item.title).toLowerCase().includes(term) || item.author_name.toLowerCase().includes(term)
      );
      renderFileList(container, filtered);
    });
  });

  // Front-End Password Change Handler
  if (passwordForm) {
    passwordForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const current_password = document.getElementById('filehub_current_password').value;
      const new_password = document.getElementById('filehub_new_password').value;
      const confirm_password = document.getElementById('filehub_confirm_password').value;

      if (passwordStatus) passwordStatus.textContent = 'Şifre güncelleniyor...';

      fetch(filehub_vars.rest_url + 'filehub/v1/change-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': filehub_vars.nonce
        },
        body: JSON.stringify({
          current_password: current_password,
          new_password: new_password,
          confirm_password: confirm_password
        })
      })
        .then(res => res.json())
        .then(resp => {
          if (resp.success) {
            if (passwordStatus) {
              passwordStatus.style.color = '#00a32a';
              passwordStatus.textContent = resp.message;
            }
            passwordForm.reset();
          } else {
            if (passwordStatus) {
              passwordStatus.style.color = '#b32d2e';
              passwordStatus.textContent = resp.error || 'Güncelleme başarısız.';
            }
          }
        })
        .catch(err => {
          if (passwordStatus) {
            passwordStatus.style.color = '#b32d2e';
            passwordStatus.textContent = 'Sunucu bağlantı hatası.';
          }
        });
    });
  }

  // Front-End Registration Handler
  if (registerForm) {
    registerForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const username = document.getElementById('filehub_reg_username').value;
      const email = document.getElementById('filehub_reg_email').value;
      const first_name = document.getElementById('filehub_reg_first_name').value;
      const last_name = document.getElementById('filehub_reg_last_name').value;
      const password = document.getElementById('filehub_reg_password').value;
      const confirm_password = document.getElementById('filehub_reg_confirm_password').value;

      if (registerStatus) {
        registerStatus.style.color = '#1d2327';
        registerStatus.textContent = 'Kayıt işlemi yapılıyor...';
      }

      fetch(filehub_vars.rest_url + 'filehub/v1/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': filehub_vars.nonce
        },
        body: JSON.stringify({
          username: username,
          email: email,
          first_name: first_name,
          last_name: last_name,
          password: password,
          confirm_password: confirm_password
        })
      })
        .then(res => res.json())
        .then(resp => {
          if (resp.success) {
            if (registerStatus) {
              registerStatus.style.color = '#00a32a';
              registerStatus.textContent = resp.message;
            }
            registerForm.reset();
            setTimeout(() => {
              window.location.reload();
            }, 1500);
          } else {
            if (registerStatus) {
              registerStatus.style.color = '#b32d2e';
              registerStatus.textContent = resp.error || 'Kayıt başarısız.';
            }
          }
        })
        .catch(err => {
          if (registerStatus) {
            registerStatus.style.color = '#b32d2e';
            registerStatus.textContent = 'Sunucu bağlantı hatası.';
          }
        });
    });
  }

  function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  refreshAllFileLists();
});
