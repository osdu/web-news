<?php
// vim:sw=2:ts=2:et

if (!$article) die("no article specified");

require 'common.inc';
require 'nntp.inc';

$s = nntp_connect("news.php.net")
  or die("failed to connect to news server");

if ($group) {
  $res = nntp_cmd($s,"GROUP $group",211)
    or die("failed to select group .".htmlspecialchars($group));
}
$res = nntp_cmd($s, "ARTICLE $article",220)
  or die("failed to get article ".htmlspecialchars($article));

$started = 0;
$inheaders = 1; $headers = array();
$masterheaders = null;
$mimetype = $boundary = $charset = $encoding = "";
$boundaries = array();
$lk = '';
while (!feof($s)) {
  $line = fgets($s, 4096);
  if ($line == ".\r\n") break;
  if ($inheaders && ($line == "\n" || $line == "\r\n")) {
    $inheaders = 0;
    if (!$started) {
      head("$group: ".format_subject($headers[subject]));
      start_article($group,$headers);
      $started = 1;
    }
    if ($headers['content-type']
        && preg_match("/charset=(\"|'|)(.+)\\1/s", $headers['content-type'], $m)) {
      $charset = trim($m[2]);
    }
    if ($headers['content-type']
        && preg_match("/boundary=(\"|'|)(.+)\\1/s", $headers['content-type'], $m)) {
      $boundaries[] = trim($m[2]);
      $boundary = end($boundaries);
    }
    if ($headers['content-type']
        && preg_match("/([^;]+)(;|\$)/", $headers['content-type'], $m)) {
      $mimetype = strtolower($m[1]);
    }
    
    $encoding = strtolower(trim($headers['content-transfer-encoding']));
    if (strlen($mimetype)
        && $mimetype != "text/plain"
        && substr($mimetype,0,10) != "multipart/") {
      # Display a link to the attachment
      $name = "";
      if ($headers['content-type']
          && preg_match("/name=(\"|'|)(.+)\\1/s", $headers['content-type'], $m))
        $name = trim($m[2]);
      else if ($headers['content-disposition']
          && preg_match("/filename=(\"|'|)(.+)\\1/s", $headers['content-type'], $m))
        $name = trim($m[2]);
      
      if ($headers['content-description'])
        $description = trim($headers['content-description']) . " ";
      else
        $description = "";
      
      echo "Attachment: [$mimetype] ${description}$name<br />\n"; 
    }
    
    if ($masterheaders == null)
      $headers = $masterheaders;
    continue;
  }
  # fix lines that started with a period and got escaped
  if (substr($line,0,2) == "..") $line = substr($line,1);
  if ($inheaders) {
    list($k,$v) = explode(": ", $line, 2);
    if ($k && $v) {
      $headers[strtolower($k)] = $v;
      $lk = strtolower($k);
    }
    else {
      $headers[$lk] .= $line;
    }
  }
  else {

    if ($boundary
        && substr($line,0,2) == '--'
        && substr($line,2,strlen($boundary)) == $boundary) {

      $inheaders = 1;

      if (substr($line,2+strlen($boundary)) == '--') {
        # end of this container
        array_pop($boundaries);
        $boundary = end($boundaries);
      } else {
        # next section; start with an inherited set of headers
        $headers = $masterheaders;
        $mimetype = "";
      }
      
      continue;
    }

    if (strlen($mimetype) && $mimetype != "text/plain")
      continue;
    
    switch($encoding) {
      case "quoted-printable":
        $line = quoted_printable_decode($line);
        break;
      case "base64":
        $line = base64_decode($line);
        break;
    }

    # this is some amazingly simplistic code to color quotes/signatures
    # differently, and turn links into real links. it actually appears
    # to work fairly well, but could easily be made more sophistimicated.
    $line = htmlentities($line,ENT_NOQUOTES,$charset);
    $line = preg_replace("/((mailto|http|ftp|nntp|news):.+?)(&gt;|\\s|\\)|\\.\\s|$)/","<a href=\"\\1\">\\1</a>\\3",$line);
    if (!$insig && $line == "-- \r\n") {
      echo "<span class=\"signature\">";
      $insig = 1;
    }
    if ($insig && $line == "\r\n") {
      echo "</span>";
      $insig = 0;
    }
    if (!$insig && substr($line,0,4) == "&gt;") {
      echo "<span class=\"quote\">$line</span>";
    }
    else {
      echo $line;
    }
  }
}
if ($inheaders && !$started) {
    head("$group: ".format_subject($headers[subject]));
    start_article($group,$headers);
}
if ($insig) echo "</span>";
echo "</pre></blockquote>";

function start_article ($group,$headers) {
  echo "<blockquote>\n";
  echo '<table border="0" cellpadding="2" cellspacing="2" width="100%">';
  # from
  echo '<tr><td class="headerlabel">From:</td><td class="headervalue">'.format_author($headers[from])."</td>\n";
  # date
  echo '<td class="headerlabel">Date:</td><td class="headervalue">'.format_date($headers["date"])."</td></tr>\n";
  # subject
  echo '<tr><td class="headerlabel">Subject:</td><td class="headervalue" colspan="3">'.format_subject($headers["subject"])."</td></tr>\n";
  echo "<tr>";
  # references
  if ($headers["references"] || $headers["in-reply-to"]) {
    $ref = $headers["references"] ? $headers["references"] : $headers["in-reply-to"];
    echo '<td class="headerlabel">References:</td><td class="headervalue">';
    $r = explode(" ", $ref);
    $c = 1;
    while (list($k,$v) = each($r)) {
      if (!$v) continue;
      $v = trim($v);
      if (!preg_match("/^<.+>\$/", $v)) continue;
      echo "<a href=\"article.php?group=$group&amp;article=".htmlspecialchars(urlencode($v))."\">".($c++)."</a>\n";
    }
    echo "</td>\n";
  }
  # groups
  if ($headers["newsgroups"]) {
    echo '<td class="headerlabel">Groups:</td><td class="headervalue">';
    $r = explode(",", chop($headers["newsgroups"]));
    while (list($k,$v) = each($r)) {
      echo "<a href=\"group.php?group=".htmlspecialchars(urlencode($v))."\">".htmlspecialchars($v)."</a>\n";
    }
    echo "</td>\n";
  }
  echo "</tr>";
  while (list($k,$v) = each($headers)) {
    echo "<!-- ", htmlspecialchars($k),": ",preg_replace("/-+/", "-", htmlspecialchars($v))," -->\n";
  }
  echo "</table></blockquote>\n";
  echo "<blockquote><pre>";
}

foot();
