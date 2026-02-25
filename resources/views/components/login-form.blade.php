<div style="max-width:400px;margin:60px auto;padding:20px;border:1px solid #ccc;border-radius:8px;">
    <h2>Login</h2>

    <form id="loginForm">
        <div style="margin-bottom:10px;">
            <label>Email</label><br>
            <input type="email" name="email" required style="width:100%;padding:8px;">
        </div>

        <div style="margin-bottom:10px;">
            <label>Password</label><br>
            <input type="password" name="password" required style="width:100%;padding:8px;">
        </div>

        <button type="submit" style="width:100%;padding:10px;">
            Login
        </button>
    </form>

    <div id="loginMessage" style="margin-top:15px;"></div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {

    document.getElementById("loginForm").addEventListener("submit", async function(e) {
        e.preventDefault();

        const messageBox = document.getElementById("loginMessage");

        const payload = {
            email: this.email.value,
            password: this.password.value
        };

        messageBox.innerHTML = "Logging in...";

        try {
            const res = await fetch("/api/login", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();

            if (!res.ok) {
                messageBox.innerHTML = `<span style="color:red">${data.message ?? "Login failed"}</span>`;
                return;
            }

            // ✅ save token
            localStorage.setItem("auth_token", data.data.token);

            messageBox.innerHTML = `<span style="color:green">Login successful</span>`;

            // redirect if you want
            window.location.href = "/dashboard";

        } catch (err) {
            messageBox.innerHTML = `<span style="color:red">Server error</span>`;
            console.error(err);
        }
    });

});
</script>
