#!/usr/bin/perl
# Vermittler für den Claude-Zugang (MCP) der Ausrüstungsverwaltung.
#
# Liegt auf dem Server im cgi-bin von www.prodad.de (Quelle im Repository:
# scripts/cgi-bin/ff-mcp.pl, Holger lädt ihn von Hand hoch; dort mit .htaccess "CGIPassAuth On").
# Grund: Apache reicht den Header Authorization an klassisches CGI weiter, an PHP (FastCGI auf
# STRATO) nicht. Claude ruft https://www.prodad.de/cgi-bin/ff-mcp.pl/<stadt> auf; der Vermittler
# leitet NUR an https://www.prodad.de/firefighting/<stadt>/mcp weiter, Authorization als
# X-FF-Authorization. Token und Body stehen nie in der Befehlszeile von curl (die Prozessliste
# sehen andere Kunden): die Header in einer Konfiguration über eine eigene Pipe (/dev/fd/3), der
# Body über die Standardeingabe. Keine Protokolle, keine temporären Dateien.
# Regeln: CLAUDE.md "Vermittler für Claude (cgi-bin)".
use strict;
use warnings;
use Fcntl qw(F_SETFD);
use POSIX ();

my $TARGET = 'https://www.prodad.de/firefighting/%s/mcp';
my $SLUG_PATTERN = qr/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/;
my $SLUG_MAX_LENGTH = 50;
my $RESERVED_SLUG = 'service';
my $MAX_BODY_BYTES = 1_048_576;
my $MAX_RESPONSE_BYTES = 4_194_304;
my $MAX_HEADER_LENGTH = 4096;
my $CONNECT_TIMEOUT = 5;
my $MAX_TIME = 25;
# Nummer der Pipe mit der Konfiguration im Prozess von curl.
my $CONFIG_FD = 3;
my @FORWARDED_METHODS = ('POST', 'GET', 'DELETE');
my @CURL_CANDIDATES = ('/usr/bin/curl', '/usr/local/bin/curl', '/bin/curl');
# Header der Anfrage: Variable der CGI-Umgebung => Name beim Weiterleiten. Keine anderen.
my @FORWARDED_HEADERS = (
    ['CONTENT_TYPE', 'Content-Type'],
    ['HTTP_ACCEPT', 'Accept'],
    ['HTTP_MCP_SESSION_ID', 'Mcp-Session-Id'],
    ['HTTP_MCP_PROTOCOL_VERSION', 'MCP-Protocol-Version'],
    ['HTTP_AUTHORIZATION', 'X-FF-Authorization'],
);
# Header der Antwort, die zurückgehen (dazu fest Cache-Control und X-Content-Type-Options).
my @RETURNED_HEADERS = ('Content-Type', 'WWW-Authenticate', 'Allow');
my %REASONS = (
    400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed',
    502 => 'Bad Gateway', 504 => 'Gateway Timeout',
);

# Nichts ins Fehlerprotokoll von Apache (auch nicht von curl, das STDERR erbt).
open(STDERR, '>', '/dev/null') or die;
binmode STDIN;
binmode STDOUT;
exit main();

sub main {
    my $slug = slug_from_path($ENV{PATH_INFO});
    return fail(404, 'not_found', 'Die Adresse wurde nicht gefunden.') unless defined $slug;

    my $method = $ENV{REQUEST_METHOD} // '';
    unless (grep { $_ eq $method } @FORWARDED_METHODS) {
        return fail(405, 'method_not_allowed', 'Diese Methode ist für die Adresse nicht erlaubt.', 'Allow', 'POST');
    }

    my ($body, $problem) = read_body();
    return fail(400, 'invalid_request', $problem) if defined $problem;

    my $curl = find_curl();
    return fail(502, 'bad_gateway', 'Der Vermittler kann die Anwendung nicht erreichen.') unless defined $curl && -d '/dev/fd';

    my ($exit, $output) = run_curl($curl, sprintf($TARGET, $slug), $method, $body);
    return fail(504, 'gateway_timeout', 'Die Anwendung hat nicht rechtzeitig geantwortet.') if $exit == 28;
    my ($status, $reason, $headers, $content) = $exit == 0 ? parse_response($output) : ();
    return fail(502, 'bad_gateway', 'Die Antwort der Anwendung ist nicht lesbar.') unless defined $status;

    my @returned;
    for my $name (@RETURNED_HEADERS) {
        my $value = header_value($headers->{lc $name});
        push @returned, $name, $value if defined $value;
    }
    respond($status, $reason, \@returned, $content);
    return 0;
}

# Kürzel der Stadt aus PATH_INFO ("/test"), Regeln wie TenantSlug; sonst undef.
sub slug_from_path {
    my ($path) = @_;
    return undef unless defined $path && $path =~ m{\A/([^/]+)\z};
    my $slug = $1;
    return undef if length($slug) > $SLUG_MAX_LENGTH || $slug !~ $SLUG_PATTERN || $slug eq $RESERVED_SLUG;
    return $slug;
}

# Body nach CONTENT_LENGTH (ohne Angabe leer). Rückgabe (Body, undef) oder (undef, Meldung).
sub read_body {
    my $length = $ENV{CONTENT_LENGTH};
    return ('', undef) if !defined $length || $length eq '';
    return (undef, 'Die Länge der Anfrage ist ungültig.') unless $length =~ /\A[0-9]{1,10}\z/;
    return (undef, 'Die Anfrage ist zu groß (höchstens 1 MiB).') if $length > $MAX_BODY_BYTES;

    my $body = '';
    while (length($body) < $length) {
        my $read = read(STDIN, $body, $length - length($body), length($body));
        return (undef, 'Die Anfrage ist unvollständig.') unless $read;
    }
    return ($body, undef);
}

sub find_curl {
    # Nur für Tests (Ersatz für curl); Apache setzt diese Variable nie aus einer Anfrage.
    my $override = $ENV{FF_MCP_CURL_BIN};
    return $override if defined $override && $override ne '';
    for my $candidate (@CURL_CANDIDATES) {
        return $candidate if -x $candidate;
    }
    return undef;
}

# Header-Wert nur aus druckbaren ASCII-Zeichen, höchstens $MAX_HEADER_LENGTH; sonst undef.
sub header_value {
    my ($value) = @_;
    return undef unless defined $value && $value =~ /\A[\x20-\x7E]{1,$MAX_HEADER_LENGTH}\z/;
    return $value;
}

# Konfiguration für curl (nur Header; Werte druckbares ASCII, daher genügt \\ und \").
sub curl_config {
    my @lines;
    for my $header (@FORWARDED_HEADERS) {
        my ($variable, $name) = @$header;
        my $value = header_value($ENV{$variable});
        if (defined $value) {
            push @lines, "$name: $value";
        } elsif ($name eq 'Content-Type' || $name eq 'Accept') {
            # Sonst setzt curl eigene Werte (Formular bzw. */*).
            push @lines, "$name:";
        }
    }
    push @lines, 'Expect:', 'User-Agent: ff-mcp-relay';
    return join('', map { 'header = "' . ($_ =~ s/([\\"])/\\$1/gr) . "\"\n" } @lines);
}

# Startet curl ohne Shell. Rückgabe (Exit-Code, Ausgabe); 28 = Zeitgrenze, 1 = sonstiger Fehler.
sub run_curl {
    my ($curl, $url, $method, $body) = @_;
    my $with_body = $method eq 'POST' || ($method eq 'DELETE' && $body ne '');
    my @args = (
        '--disable', '--stderr', '/dev/null', '--silent', '--include', '--http1.1',
        '--proto', '=https', '--noproxy', '*',
        '--connect-timeout', $CONNECT_TIMEOUT, '--max-time', $MAX_TIME,
        '--max-filesize', $MAX_RESPONSE_BYTES, '--config', "/dev/fd/$CONFIG_FD",
        # Binär und ohne Sonderbedeutung im Inhalt; curl liest den Body vor dem Senden ganz.
        ($with_body ? ('--data-binary', '@-') : ()),
        ($method eq 'DELETE' ? ('--request', 'DELETE') : ()),
        '--url', $url,
    );

    local $SIG{PIPE} = 'IGNORE';
    pipe(my $config_r, my $config_w) or return (1, '');
    pipe(my $body_r, my $body_w) or return (1, '');
    pipe(my $out_r, my $out_w) or return (1, '');
    my $pid = fork();
    return (1, '') unless defined $pid;
    if ($pid == 0) {
        close $_ for $config_w, $body_w, $out_r;
        open(STDIN, '<&', $body_r) or POSIX::_exit(127);
        open(STDOUT, '>&', $out_w) or POSIX::_exit(127);
        if (fileno($config_r) != $CONFIG_FD) {
            POSIX::dup2(fileno($config_r), $CONFIG_FD) or POSIX::_exit(127);
        }
        open(my $config_fd, '<&=', $CONFIG_FD) or POSIX::_exit(127);
        fcntl($config_fd, F_SETFD, 0) or POSIX::_exit(127);
        # curl erbt weder das Token noch andere Variablen der Anfrage.
        %ENV = (PATH => '/usr/bin:/bin');
        exec { $curl } $curl, @args or POSIX::_exit(127);
    }
    close $_ for $config_r, $body_r, $out_w;
    binmode $_ for $config_w, $body_w, $out_r;

    my $output = '';
    my $exit = eval {
        local $SIG{ALRM} = sub { die "timeout\n" };
        alarm($MAX_TIME + 5);
        # Zuerst die Konfiguration (curl liest sie vor dem Body; sie passt in den Puffer der Pipe).
        print {$config_w} curl_config();
        close $config_w;
        print {$body_w} $body if $with_body;
        close $body_w;
        while (1) {
            my $read = read($out_r, my $chunk, 65536);
            last unless $read;
            $output .= $chunk;
            die "too large\n" if length($output) > $MAX_RESPONSE_BYTES + 65536;
        }
        close $out_r;
        waitpid($pid, 0);
        alarm(0);
        $? == 0 ? 0 : ($? & 127 ? 1 : $? >> 8);
    };
    alarm(0);
    return ($exit == 127 ? 1 : $exit, $output) if defined $exit;

    kill 'KILL', $pid;
    waitpid($pid, 0);
    return ($@ eq "timeout\n" ? 28 : 1, '');
}

# Ausgabe von curl --include: Header (1xx übersprungen), dann Body. Rückgabe (Status, Text,
# {name klein => Wert}, Body) oder leer.
sub parse_response {
    my ($raw) = @_;
    while (1) {
        my $end = index($raw, "\r\n\r\n");
        return () if $end < 0;
        my @lines = split /\r\n/, substr($raw, 0, $end);
        $raw = substr($raw, $end + 4);
        my $status_line = shift(@lines) // '';
        return () unless $status_line =~ m{\AHTTP/[0-9.]+ ([1-5][0-9][0-9])(?: ([^\r\n]*))?\z};
        my ($status, $reason) = ($1, $2 // '');
        next if $status < 200;

        my %headers;
        for my $line (@lines) {
            $headers{lc $1} = $2 if $line =~ /\A([A-Za-z0-9-]+):[ \t]*(.*?)[ \t]*\z/;
        }
        return () if length($raw) > $MAX_RESPONSE_BYTES;
        $reason = $REASONS{$status} // 'Status' unless $reason =~ /\A[A-Za-z][A-Za-z -]{0,39}\z/;
        return ($status, $reason, \%headers, $raw);
    }
}

sub respond {
    my ($status, $reason, $headers, $content) = @_;
    my $head = "Status: $status $reason\r\n";
    my @pairs = @$headers;
    while (my ($name, $value) = splice(@pairs, 0, 2)) {
        $head .= "$name: $value\r\n";
    }
    $head .= "Cache-Control: no-store\r\nX-Content-Type-Options: nosniff\r\n\r\n";
    print STDOUT $head, $content;
}

# Fehler im Fehlerformat der App ({"error","message"}); Rückgabe 0 (Exit-Code des Skripts).
sub fail {
    my ($status, $code, $message, @headers) = @_;
    respond($status, $REASONS{$status}, ['Content-Type', 'application/json; charset=utf-8', @headers],
        '{"error":"' . $code . '","message":"' . $message . '"}');
    return 0;
}
