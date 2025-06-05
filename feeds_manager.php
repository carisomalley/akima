<?php
  require __DIR__ . '/auth_check.php'; // feeds_manager.php
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RSS Feed Manager</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Bootstrap 5 CSS -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
  <!-- Bootstrap Icons -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet"
  >
  <!-- DataTables CSS -->
  <link
    href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css"
    rel="stylesheet"
  >

  <style>
    /* Tweak the action buttons column */
    #feedsTable td.actions {
      white-space: nowrap;
      width: 1%;
    }
    /* Vertically center content */
    #feedsTable td, #feedsTable th {
      vertical-align: middle;
    }
  </style>
</head>
<body class="bg-light">

  <div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="d-flex align-items-center">
        <!-- Home button linking back to index.php -->
        <a href="index.php" class="btn btn-outline-secondary me-3">
          <i class="bi bi-house"></i> Home
        </a>
        <h1 class="h3 mb-0">RSS Feeds</h1>
      </div>
      <button
        class="btn btn-success"
        data-bs-toggle="modal"
        data-bs-target="#addFeedModal"
      >
        <i class="bi bi-plus-lg me-1"></i> Add Feed
      </button>
    </div>

    <div class="table-responsive shadow-sm bg-white rounded">
      <table id="feedsTable" class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>URL</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

  </div>

  <!-- Add Feed Modal -->
  <div class="modal fade" id="addFeedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form id="addFeedForm" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add RSS Feed</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Feed Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Feed URL</label>
            <input type="url" name="url" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-bs-dismiss="modal"
          >Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i> Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Feed Modal (hidden until invoked) -->
  <div class="modal fade" id="editFeedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form id="editFeedForm" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit RSS Feed</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id">
          <div class="mb-3">
            <label class="form-label">Feed Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Feed URL</label>
            <input type="url" name="url" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-bs-dismiss="modal"
          >Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-pencil-fill me-1"></i> Update
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Core JS -->
  <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

  <script>
  $(function(){
    const table = $('#feedsTable').DataTable({
      ajax: { url:'api/feeds.php', dataSrc:'data' },
      columns: [
        { data: 'name' },
        {
          data: 'url',
          render: url => `<a href="${url}" target="_blank" rel="noopener">${url}</a>`
        },
        {
          data: null,
          className: 'actions text-center',
          orderable: false,
          render(row) {
            return `
              <div class="btn-group btn-group-sm" role="group">
                <button
                  class="btn btn-outline-primary edit-btn"
                  data-id="${row.id}"
                  data-name="${row.name}"
                  data-url="${row.url}"
                >
                  <i class="bi bi-pencil-fill"></i>
                </button>
                <button
                  class="btn btn-outline-danger delete-btn"
                  data-id="${row.id}"
                >
                  <i class="bi bi-trash-fill"></i>
                </button>
              </div>`;
          }
        }
      ],
      paging: false,
      info: false,
      searching: false
    });

    // ─── Add Feed ─────────────────────────────────────────────────────────
    $('#addFeedForm').submit(function(e){
      e.preventDefault();
      $.post('api/add_feed.php', $(this).serialize(), function(resp){
        if (resp.success) {
          // Close the modal & reset the form
          bootstrap.Modal.getInstance($('#addFeedModal')[0]).hide();
          $('#addFeedForm')[0].reset();

          // Instead of just reloading DataTables, redirect to feeds_sync.php.
          // feeds_sync.php will re-import all feeds then redirect to feeds_view.php
          window.location.href = 'feeds_sync.php';
        } else {
          alert(resp.error);
        }
      }, 'json');
    });

    // ─── Open Edit Modal ─────────────────────────────────────────────────
    $('#feedsTable').on('click', '.edit-btn', function(){
      const btn = $(this);
      $('#editFeedForm')
        .find('[name=id]').val(btn.data('id')).end()
        .find('[name=name]').val(btn.data('name')).end()
        .find('[name=url]').val(btn.data('url'));
      new bootstrap.Modal($('#editFeedModal')[0]).show();
    });

    // ─── Update Feed ─────────────────────────────────────────────────────
    $('#editFeedForm').submit(function(e){
      e.preventDefault();
      $.post('api/update_feed.php', $(this).serialize(), function(resp){
        if (resp.success) {
          // Close the modal
          bootstrap.Modal.getInstance($('#editFeedModal')[0]).hide();

          // Redirect to feeds_sync.php → which sends you on to feeds_view.php
          window.location.href = 'feeds_sync.php';
        } else {
          alert(resp.error);
        }
      }, 'json');
    });

    // ─── Delete Feed ─────────────────────────────────────────────────────
    $('#feedsTable').on('click', '.delete-btn', function(){
      if (!confirm('Are you sure you want to delete this feed?')) return;
      const feedId = $(this).data('id');
      $.post('api/delete_feed.php', { id: feedId }, function(resp){
        if (resp.success) {
          // Once deleted, immediately redirect to feeds_sync.php
          window.location.href = 'feeds_sync.php';
        } else {
          alert(resp.error);
        }
      }, 'json');
    });
  });
  </script>
</body>
</html>
