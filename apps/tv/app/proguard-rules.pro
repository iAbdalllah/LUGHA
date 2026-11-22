# JNI entry points are looked up by name from the Rust library.
-keep class app.lugha.tv.link.NativeLink { native <methods>; }
-keepclassmembers class * { @kotlinx.serialization.Serializable *; }
