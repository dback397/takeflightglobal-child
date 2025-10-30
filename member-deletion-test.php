<form method="post" action="">
    <input type="hidden" name="member_id" value="UNI00001">
    <?php wp_nonce_field('tfg_delete_member', '_tfg_nonce'); ?>
    <button type="submit">Delete My Profile</button>
</form>
