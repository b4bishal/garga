<?php
session_start();
$sessionFile = __DIR__ . "/session.json";
$sessions = file_exists($sessionFile) ? json_decode(file_get_contents($sessionFile), true) : [];
if (!isset($_COOKIE['sessionid']) || !isset($sessions[$_COOKIE['sessionid']])) {
    header("Location: login.php");
    exit;
}

$title = '';
$content = '';
$generated = false;
$useGD = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? "Sample Title";
    $content = $_POST['content'] ?? "Sample content for the letter.";

    // Attempt to use GD if available
    if (function_exists('imagecreatetruecolor')) {
        // Font path
        $font_path = __DIR__ . '/assets/fonts/Poppins-Regular.ttf';
        if (file_exists($font_path)) {
            $useGD = true;

            // Image dimensions
            $width = 2480; $height = 3508;
            $image = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 0, 0, $width, $height, $white);

            // Load letter background if exists
            $letterpad = 'assets/images/letter.png';
            if(file_exists($letterpad)){
                $bg = imagecreatefrompng($letterpad);
                imagecopyresampled($image, $bg, 0,0,0,0,$width,$height,imagesx($bg),imagesy($bg));
                imagedestroy($bg);
            }

            $black = imagecolorallocate($image,0,0,0);
            $title_size = 60; $content_size = 40;

            // Title
            $title_box = imagettfbbox($title_size,0,$font_path,$title);
            $title_width = $title_box[2]-$title_box[0];
            $title_x = ($width-$title_width)/2;
            $title_y = 948; // header + offset

            imagettftext($image,$title_size,0,$title_x,$title_y,$black,$font_path,$title);

            // Content
            $content_lines = explode("\n", wordwrap($content,70));
            $line_height = $content_size * 1.5;
            $current_y = $title_y + 100;
            $bottom_margin = 2933;
            foreach($content_lines as $line){
                $line_box = imagettfbbox($content_size,0,$font_path,$line);
                $line_width = $line_box[2]-$line_box[0];
                $x = ($width-$line_width)/2;
                imagettftext($image,$content_size,0,$x,$current_y,$black,$font_path,$line);
                $current_y += $line_height;
                if($current_y > $bottom_margin) break;
            }

            $output_file = __DIR__.'/assets/images/title.png';
            imagepng($image,$output_file);
            imagedestroy($image);
            $generated = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Write Letter - Garga Copy Udhyog</title>
<style>
body {
    font-family:'Poppins',sans-serif;
    background:#f0f0f0;
    padding:20px;
    display:flex;
    flex-direction:column;
    align-items:center;
}
h2 {color:#00796b; margin-bottom:20px;}
form {width:600px; background:#fff; padding:25px 30px; border-radius:16px; box-shadow:0 6px 20px rgba(0,0,0,0.08); margin-bottom:30px;}
form input, form textarea, form button {width:100%; padding:12px; margin:10px 0; border-radius:8px; border:1px solid #ccc; font-size:15px;}
form button {background:#26a69a; color:white; font-weight:600; cursor:pointer; border:none; transition:0.3s;}
form button:hover {background:#00796b;}
.letter-preview {position:relative; width:1240px; height:1754px; background:url('assets/images/letter.png') no-repeat; background-size:cover; border:1px solid #ccc; margin-bottom:50px;}
.letter-title, .letter-content {position:absolute; left:calc(69px/2); right:calc((2480-2416)/2); color:#000; word-wrap:break-word; text-align:center;}
.letter-title {top:170px; font-size:28px; font-weight:600;}
.letter-content {top:430px; font-size:18px; line-height:1.5; padding:0 20px; white-space:pre-wrap;}
</style>
</head>
<body>
<h2>Write Official Letter</h2>
<form method="POST">
    <input type="text" name="title" placeholder="Enter Letter Title" required value="<?php echo htmlspecialchars($title); ?>">
    <textarea name="content" placeholder="Enter Letter Content" rows="10" required><?php echo htmlspecialchars($content); ?></textarea>
    <button type="submit">Done</button>
</form>

<?php if($generated && $useGD): ?>
<div class="letter-preview">
    <div class="letter-title"><?php echo htmlspecialchars($title); ?></div>
    <div class="letter-content"><?php echo nl2br(htmlspecialchars($content)); ?></div>
</div>
<p style="text-align:center;"><a href="assets/images/title.png" target="_blank">Download Generated Letter</a></p>
<?php elseif($_SERVER['REQUEST_METHOD']==='POST'): ?>
<div class="letter-preview">
    <div class="letter-title"><?php echo htmlspecialchars($title); ?></div>
    <div class="letter-content"><?php echo nl2br(htmlspecialchars($content)); ?></div>
</div>
<p style="text-align:center; color:red;">GD or font not available, preview rendered with HTML/CSS.</p>
<?php endif; ?>
</body>
</html>
