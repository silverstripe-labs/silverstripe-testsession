<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<% base_tag %>
		$MetaTags
        <% require css('framework/client/dist/styles/debug.css') %>
		<% require css('testsession/client/styles/styles.css') %>
	</head>
	<body>
		<div class="info">
			<h1>SilverStripe TestSession</h1>
		</div>
		<div class="content">
			<p>Start a new test session</p>
			$StartForm
		</div>
	</body>
</html>
