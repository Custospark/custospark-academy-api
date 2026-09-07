<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #0b2a55; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .sub { color: #64748b; font-size: 10px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; }
        th { background: #0b2a55; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:nth-child(even) td { background: #f1f5f9; }
    </style>
</head>
<body>
    <h1>{{ $course->title }} - Learners</h1>
    <div class="sub">Generated {{ $generatedAt->format('j F Y, H:i') }} &middot; {{ count($rows) }} learner(s)</div>
    <table>
        <thead>
            <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Applied</th><th>Admitted</th></tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $row[0] }}</td>
                    <td>{{ $row[1] }}</td>
                    <td>{{ $row[2] }}</td>
                    <td>{{ $row[3] }}</td>
                    <td>{{ $row[4] }}</td>
                    <td>{{ $row[5] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
