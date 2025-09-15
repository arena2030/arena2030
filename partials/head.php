<?php
  // You can pass $page_css (array or string) before including this file to attach page-level CSS.
  $styles = [];
  if (isset($page_css)) {
    if (is_array($page_css)) { $styles = $page_css; }
    else { $styles = [$page_css]; }
  }
?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title>Arena</title>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/base.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/layout.css">
<?php foreach ($styles as $href): ?>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>">
<?php endforeach; ?>
</head>
<body>
