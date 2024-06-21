<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View sent emails (dev)</title>
</head>
<body style="padding:1rem;">

<% if Email %>
<a href="/__sparkpost/sent_emails">Back</a>
<hr>
    $Email.RAW
<% else %>
<h1>Sent emails</h1>

<p>$Emails.count sent emails</p>

<table>
    <thead>
        <tr>
        <th>Email</th>
    <th>Sent</th>
        </tr>
    </thead>
<% loop Emails %>
<tr>
<td><a href="?view=$Name">$Name</a></td>
<td>$Date</td>
</tr>
<% end_loop %>
</table>
<% end_if %>
</body>
</html>
