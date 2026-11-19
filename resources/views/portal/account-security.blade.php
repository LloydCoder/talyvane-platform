<details class="password-change">
    <summary>Change account password</summary>
    <form method="POST" action="{{ route('account.password.update') }}">
        @csrf @method('PATCH')
        @if ($errors->has('current_password') || $errors->has('password'))
            <div class="form-alert" role="alert">{{ $errors->first('current_password') ?: $errors->first('password') }}</div>
        @endif
        <label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="14" required>
        <label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="14" required>
        <button class="button button-dark" type="submit">Change password ↗</button>
    </form>
</details>
