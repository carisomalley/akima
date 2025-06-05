<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Akima – a social media assistant</title>
  <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,700&display=swap" rel="stylesheet"/>
  <style>
    * {
      margin: 0; padding: 0;
      box-sizing: border-box;
      font-family: 'Roboto', sans-serif;
    }
    body {
      height: 100vh;
      background: #ffffff;       /* white background */
      color: #1c1f2e;            /* dark text */
      display: flex;
      flex-direction: column;
    }
    header {
      position: relative;
      padding: 1.5rem 2rem;      /* reduced bottom padding */
      text-align: center;
    }
    header h1 {
      font-size: 3rem;
      font-weight: 700;
      letter-spacing: 1px;
    }
    header p {
      font-size: 1.2rem;
      font-weight: 300;
      margin-top: 0.5rem;
      opacity: 0.85;
    }
    /* Admin button */
    #admin-btn {
      position: absolute;
      top: 1.5rem;
      right: 2rem;
      background: transparent;
      border: 2px solid #1c1f2e; /* dark border */
      padding: 0.5rem 1rem;
      border-radius: 4px;
      font-weight: 500;
      text-decoration: none;
      color: #1c1f2e;            /* dark text */
      transition: background 0.3s, color 0.3s;
    }
    #admin-btn:hover {
      background: #1c1f2e;
      color: #ffffff;
    }
    main {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start; /* top-align main content */
      padding: 1rem;
      /* no vertical centering, so image is right under header */
    }
    /* Image between title and buttons */
    .image-container {
      text-align: center;
      margin-bottom: 2rem;
    }
    .image-container img {
      width: 80%;
      max-width: 400px;
      height: auto;
      border-radius: 4px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .btn-container {
      display: flex;
      gap: 2rem;
      flex-wrap: wrap;
      justify-content: center;
    }
    .btn {
      display: inline-block;
      padding: 1rem 2rem;
      font-size: 1.1rem;
      font-weight: 500;
      text-decoration: none;
      color: #1c1f2e;          /* dark text */
      background: #f0f0f5;     /* light grey button */
      border-radius: 6px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    }
    @media (max-width: 600px) {
      header h1 { font-size: 2.5rem; }
      .btn { width: 100%; text-align: center; }
      .btn-container { gap: 1rem; }
    }
  </style>
</head>
<body>
  <header>
    <h1>Akima</h1>
    <p>a social media assistant</p>
    <a id="admin-btn" href="admin.php">Admin</a>
  </header>
  <main>
    <div class="image-container">
      <img src="assets/resist.png" alt="Resist graphic for Akima" />
    </div>
    <div class="btn-container">
      <a class="btn" href="feeds_manager.php">Add / Edit RSS Feed</a>
      <a class="btn" href="feeds_view.php">Create Post</a>
    </div>
  </main>
</body>
</html>
