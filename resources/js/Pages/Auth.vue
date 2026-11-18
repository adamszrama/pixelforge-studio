<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Shell from '../Components/Shell.vue';
const props = defineProps<{ registering: boolean }>();
const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
function submit() {
  form.post(props.registering ? '/register' : '/login', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  });
}
</script>
<template>
  <Shell
    ><Head :title="registering ? 'Create account' : 'Welcome back'" />
    <main class="auth-layout">
      <section class="auth-art">
        <div class="eyebrow">A LITTLE IMAGINATION GOES A LONG WAY</div>
        <h1>Make room<br />for your<br /><em>next idea.</em></h1>
        <div class="abstract-art" aria-hidden="true">
          <div class="orb"></div>
          <div class="arch"></div>
          <div class="small-orb"></div>
          <div class="art-grid"></div>
        </div>
        <p>
          A quiet space to turn prompts into pictures.<br />Clear pricing, from the first word to
          the final pixel.
        </p>
      </section>
      <section class="auth-panel">
        <div class="eyebrow">YOUR CREATIVE WORKSPACE</div>
        <h2>{{ registering ? 'Start something good.' : 'Welcome back.' }}</h2>
        <p>
          {{
            registering
              ? 'Create an account and bring your ideas to life.'
              : 'Your next image starts here.'
          }}
        </p>
        <form @submit.prevent="submit" class="auth-form">
          <label v-if="registering"
            >Name<input v-model="form.name" autocomplete="name" required maxlength="100"
          /></label>
          <label
            >Email address<input v-model="form.email" type="email" autocomplete="email" required
          /></label>
          <label
            >Password<input
              v-model="form.password"
              type="password"
              :autocomplete="registering ? 'new-password' : 'current-password'"
              required
              :minlength="registering ? 12 : 1"
          /></label>
          <label v-if="registering"
            >Confirm password<input
              v-model="form.password_confirmation"
              type="password"
              autocomplete="new-password"
              required
              minlength="12"
          /></label>
          <p v-if="registering" class="hint">
            At least 12 characters. Demo credits are free to add.
          </p>
          <div v-if="Object.keys(form.errors).length" class="error-box" role="alert">
            <p v-for="error in form.errors" :key="error">{{ error }}</p>
          </div>
          <button class="button primary" :disabled="form.processing">
            {{
              form.processing
                ? 'One moment…'
                : registering
                  ? 'Create your account ↗'
                  : 'Enter the studio ↗'
            }}
          </button>
        </form>
        <p class="auth-switch">
          {{ registering ? 'Already have an account?' : 'New here?' }}
          <Link :href="registering ? '/login' : '/register'">{{
            registering ? 'Sign in' : 'Create an account'
          }}</Link>
        </p>
        <div class="demo-note">
          <span>◈</span>
          <p>
            This is a demo studio. Images are abstract placeholders, and buying credits never takes
            a real payment.
          </p>
        </div>
      </section>
    </main></Shell
  >
</template>
