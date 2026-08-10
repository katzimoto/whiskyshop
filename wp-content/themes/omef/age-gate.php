<?php
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta content="width=device-width, initial-scale=1" name="viewport">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'omef-age-gate' ); ?>>
<?php wp_body_open(); ?>
<main class="omef-age-gate__main">
	<section aria-labelledby="omef-age-gate-title" class="omef-age-gate__card">
		<p class="omef-eyebrow">חוזק חבית</p>
		<h1 id="omef-age-gate-title">הכניסה מגיל 18 ומעלה</h1>
		<p>האתר כולל תוכן ומוצרים אלכוהוליים. המשך הגלישה מאשר/ת כי מלאו לך 18 שנים לפחות.</p>
		<?php if ( $age_gate_error ) : ?>
			<p class="omef-age-gate__error" role="alert">יש לאשר שמלאו לך 18 שנים לפחות.</p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'omef_age_gate', 'omef_age_gate_nonce' ); ?>
			<input name="omef_age_gate" type="hidden" value="1">
			<label class="omef-age-gate__confirm"><input name="omef_confirmed_18" type="checkbox" value="1"> מלאו לי 18 שנים לפחות</label>
			<button type="submit">כניסה לאתר</button>
		</form>
		<p class="omef-age-gate__leave"><a href="https://www.google.com/">אינני בן/בת 18</a></p>
	</section>
</main>
<?php wp_footer(); ?>
</body>
</html>
