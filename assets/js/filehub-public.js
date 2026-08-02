/* GNN FileHub NextGen - Zero Dependency Native Public Drag & Drop JS */
document.addEventListener('DOMContentLoaded', function () {
  const dropZone = document.getElementById('filehub-dropzone');
  const fileInput = document.getElementById('filehub-file-input');
  const progressBar = document.getElementById('filehub-progress-bar');
  const progressFill = document.getElementById('filehub-progress-fill');
  const statusText = document.getElementById('filehub-status-text');
  const fileListContainer = document.getElementById('filehub-file-list');

  if (!dropZone || !fileInput) return;

  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, preventDefaults, false);
  });

  function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
  }

  ['dragenter', 'dragover'].forEach(eventName => {
    dropZone.classList.add('filehub-highlight');
  });

  ['dragleave', 'drop'].forEach(eventName => {
    dropZone.classList.remove('filehub-highlight');
  });

  dropZone.addEventListener('drop', handleDrop, false);
  fileInput.addEventListener('change', function () {
    if (this.files.length > 0) {
      uploadFile(this.files[0]);
    }
  });

  function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    if (files.length > 0) {
      uploadFile(files[0]);
    }
  }

  function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);

    const xhr = new XMLHttpRequest();
    const startTime = new Date().getTime();

    if (progressBar) progressBar.style.display = 'block';
    if (statusText) statusText.textContent = 'Yükleme başlatılıyor...';

    xhr.upload.addEventListener('progress', function (e) {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        const elapsedTime = (new Date().getTime() - startTime) / 1000;
        const speedBytes = elapsedTime > 0 ? e.loaded / elapsedTime : 0;
        const speedMB = (speedBytes / (1024 * 1024)).toFixed(2);

        if (progressFill) progressFill.style.width = percent + '%';
        if (statusText) statusText.textContent = `Yükleniyor: %${percent} (${speedMB} MB/s)`;
      }
    });

    xhr.onreadystatechange = function () {
      if (xhr.readyState === XMLHttpRequest.DONE) {
        if (xhr.status === 200) {
          if (statusText) statusText.textContent = 'Yükleme başarıyla tamamlandı!';
          if (progressFill) progressFill.style.width = '100%';
          setTimeout(() => {
            if (progressBar) progressBar.style.display = 'none';
            fetchFileList();
          }, 1500);
        } else {
          try {
            const resp = JSON.parse(xhr.responseText);
            if (statusText) statusText.textContent = 'Hata: ' + (resp.error || 'Yükleme başarısız.');
          } catch (err) {
            if (statusText) statusText.textContent = 'Sunucu yükleme hatası.';
          }
        }
      }
    };

    xhr.open('POST', filehub_vars.rest_url + 'filehub/v1/upload', true);
    xhr.setRequestHeader('X-WP-Nonce', filehub_vars.nonce);
    xhr.send(formData);
  }

  function fetchFileList() {
    if (!fileListContainer) return;
    fetch(filehub_vars.rest_url + 'filehub/v1/files', {
      headers: {
        'X-WP-Nonce': filehub_vars.nonce
      }
    })
      .then(res => res.json())
      .then(data => {
        if (!Array.isArray(data)) return;
        let html = '<table class="widefat striped"><thead><tr><th>Dosya Adı</th><th>Boyut</th><th>Sürücü</th><th>Yükleyen</th><th>İndirme</th><th>İşlem</th></tr></thead><tbody>';
        if (data.length === 0) {
          html += '<tr><td colspan="6">Henüz dosya yüklenmedi.</td></tr>';
        } else {
          data.forEach(item => {
            html += `<tr>
              <td><strong>${item.title}</strong></td>
              <td>${item.file_size}</td>
              <td><span class="filehub-driver-badge">${item.driver}</span></td>
              <td>${item.author_name}</td>
              <td>${item.download_count}</td>
              <td><a href="${item.download_url}" class="button button-secondary button-small" target="_blank">İndir</a></td>
            </tr>`;
          });
        }
        html += '</tbody></table>';
        fileListContainer.innerHTML = html;
      })
      .catch(err => console.error(err));
  }

  fetchFileList();
});
