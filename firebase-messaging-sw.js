
/* eslint-env serviceworker */
/* global importScripts, firebase, self */
// firebase-messaging-sw.js
importScripts('https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.6.1/firebase-messaging-compat.js');

// 1. Initialize Firebase (Replace with your actual config from Firebase Console)
const firebaseConfig = {
    apiKey: "AIzaSyD_E8JfmScnhsxqW-sBCOfW8kRFdNcrGIk",
    authDomain: "campuspulse-bfd09.firebaseapp.com",
    projectId: "campuspulse-bfd09",
    storageBucket: "campuspulse-bfd09.firebasestorage.app",
    messagingSenderId: "380453135946",
    appId: "1:380453135946:web:00e83d9df74b17c19ba8b3",
    measurementId: "G-Z88LVC6TST"
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

// 2. Handle background messages
messaging.onBackgroundMessage(function (payload) {
    console.log('[firebase-messaging-sw.js] Received background message ', payload);

    const notificationTitle = payload.notification.title;
    const notificationOptions = {
        body: payload.notification.body,
        icon: '/img/favicon.ico'
    };

    self.registration.showNotification(notificationTitle, notificationOptions);
});