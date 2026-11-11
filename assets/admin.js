/* Echo Skip Seen: admin backfill runner. One batch per request; stops when the page closes. */
(function () {
  'use strict';

  var config = window.echoSkipSeen;
  var button = document.getElementById('ess-backfill');
  var restart = document.getElementById('ess-backfill-restart');
  var status = document.getElementById('ess-backfill-status');
  if (!config || !button || !status) {
    return;
  }

  function describe(data) {
    return 'Up to post ' + data.last_id + ': ' + data.scanned + ' scanned, ' + data.with_url +
      ' with a source URL, ' + data.written + ' rows written, ' + data.present + ' already present, ' +
      data.rejected + ' rejected, ' + data.expired + ' older than retention.';
  }

  function run(fromStart) {
    button.disabled = true;
    if (restart) {
      restart.disabled = true;
    }
    var body = new URLSearchParams();
    body.append('action', config.action);
    body.append('nonce', config.nonce);
    if (fromStart) {
      body.append('restart', '1');
    }
    status.textContent = config.running + '...';

    fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error(json && json.data && json.data.message ? json.data.message : 'unknown error');
        }
        status.textContent = describe(json.data);
        if (json.data.done) {
          status.textContent += ' ' + config.done;
          button.disabled = false;
          if (restart) {
            restart.disabled = false;
          }
          return;
        }
        run(false);
      })
      .catch(function (error) {
        status.textContent = config.failed + ' ' + error.message;
        button.disabled = false;
        if (restart) {
          restart.disabled = false;
        }
      });
  }

  button.addEventListener('click', function () { run(false); });
  if (restart) {
    restart.addEventListener('click', function () { run(true); });
  }
}());
