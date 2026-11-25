# LUGHA Remote Play native core: chiaki-ng's library (AGPL-3.0, LAN play only) plus a small flat C API (lugha_rp.h)
# that the Windows (Rust), Mac and iPhone (Swift) apps call. Every dependency is built from source, so the result is
# the same on each OS and never picks up libraries installed on the build machine.
cmake_minimum_required(VERSION 3.20)
project(lugha_rp C CXX)

set(CHIAKI_DIR "${CMAKE_CURRENT_SOURCE_DIR}/../../../apps/mobile/third_party/chiaki-ng" CACHE PATH "chiaki-ng source")
set(CMAKE_POSITION_INDEPENDENT_CODE ON)
# CMake 4 refuses projects that ask for a version below 3.5 (libevent, json-c, ...); let them build.
set(CMAKE_POLICY_VERSION_MINIMUM 3.5 CACHE STRING "" FORCE)
set(BUILD_SHARED_LIBS OFF CACHE BOOL "" FORCE)
# Never link what Homebrew/MacPorts installed (other CPU type, other versions): everything here is built from source.
list(APPEND CMAKE_IGNORE_PATH /usr/local/bin /usr/local/lib /usr/local/include /usr/local/opt
    /opt/homebrew/bin /opt/homebrew/lib /opt/homebrew/include /opt/homebrew/opt /opt/local/bin /opt/local/lib)

# Windows (clang-cl): recent clang turns several old-C habits into errors that these libraries still have; keep
# them warnings so one missing prototype in a dependency does not stop the build.
if(MSVC AND CMAKE_C_COMPILER_ID STREQUAL "Clang")
    add_compile_options(
        $<$<COMPILE_LANGUAGE:C>:-Wno-error=implicit-function-declaration>
        $<$<COMPILE_LANGUAGE:C>:-Wno-error=implicit-int>
        $<$<COMPILE_LANGUAGE:C>:-Wno-error=int-conversion>
        $<$<COMPILE_LANGUAGE:C>:-Wno-error=incompatible-pointer-types>
        $<$<COMPILE_LANGUAGE:C>:-Wno-error=incompatible-function-pointer-types>
        $<$<COMPILE_LANGUAGE:C>:-Wno-error=return-type>)
    add_compile_definitions(_CRT_SECURE_NO_WARNINGS _CRT_NONSTDC_NO_DEPRECATE)
endif()

# libevent (chiaki's internet hole punching links it on desktop even though LAN play never uses it).
include(FetchContent)
set(EVENT__DISABLE_OPENSSL ON CACHE BOOL "" FORCE)
set(EVENT__DISABLE_MBEDTLS ON CACHE BOOL "" FORCE)
set(EVENT__DISABLE_TESTS ON CACHE BOOL "" FORCE)
set(EVENT__DISABLE_SAMPLES ON CACHE BOOL "" FORCE)
set(EVENT__DISABLE_BENCHMARK ON CACHE BOOL "" FORCE)
set(EVENT__DISABLE_REGRESS ON CACHE BOOL "" FORCE)
set(EVENT__LIBRARY_TYPE STATIC CACHE STRING "" FORCE)
FetchContent_Declare(libevent URL https://github.com/libevent/libevent/releases/download/release-2.1.12-stable/libevent-2.1.12-stable.tar.gz
    URL_HASH SHA256=92e6de1be9ec176428fd2367677e61ceffc2ee1cb119035037a27d346b0403bb)
FetchContent_MakeAvailable(libevent)
add_library(lugha_libevent INTERFACE)
target_link_libraries(lugha_libevent INTERFACE event_core_static event_extra_static)
target_include_directories(lugha_libevent INTERFACE "${libevent_SOURCE_DIR}/include" "${libevent_BINARY_DIR}/include")
add_library(PkgConfig::LIBEVENT ALIAS lugha_libevent)
# No pkg-config: every dependency is built here, and chiaki's own pkg-config lookup of libevent would clash with the
# target above. "OFF" (not *-NOTFOUND) keeps find_program from searching again.
set(PKG_CONFIG_EXECUTABLE OFF CACHE FILEPATH "" FORCE)

# protoc for chiaki's takion.proto (nanopb then needs Python with the `protobuf` module): the official prebuilt one.
if(CMAKE_HOST_WIN32)
    set(PROTOC_ZIP protoc-29.3-win64.zip)
    set(PROTOC_SHA 57ea59e9f551ad8d71ffaa9b5cfbe0ca1f4e720972a1db7ec2d12ab44bff9383)
elseif(CMAKE_HOST_APPLE AND CMAKE_HOST_SYSTEM_PROCESSOR MATCHES "arm64|aarch64")
    set(PROTOC_ZIP protoc-29.3-osx-aarch_64.zip)
    set(PROTOC_SHA 2b8a3403cd097f95f3ba656e14b76c732b6b26d7f183330b11e36ef2bc028765)
elseif(CMAKE_HOST_APPLE)
    set(PROTOC_ZIP protoc-29.3-osx-x86_64.zip)
    set(PROTOC_SHA 9a788036d8f9854f7b03c305df4777cf0e54e5b081e25bf15252da87e0e90875)
else()
    set(PROTOC_ZIP protoc-29.3-linux-x86_64.zip)
    set(PROTOC_SHA 3e866620c5be27664f3d2fa2d656b5f3e09b5152b42f1bedbf427b333e90021a)
endif()
FetchContent_Declare(protoc URL https://github.com/protocolbuffers/protobuf/releases/download/v29.3/${PROTOC_ZIP} URL_HASH SHA256=${PROTOC_SHA})
FetchContent_MakeAvailable(protoc)
find_program(PROTOC protoc PATHS "${protoc_SOURCE_DIR}/bin" NO_DEFAULT_PATH REQUIRED)

# json-c cannot size ssize_t with MSVC (it has SSIZE_T); tell it, so its check is skipped.
if(MSVC)
    set(HAVE_SIZEOF_SSIZE_T TRUE CACHE INTERNAL "")
    set(SIZEOF_SSIZE_T ${CMAKE_SIZEOF_VOID_P} CACHE INTERNAL "")
endif()

set(CHIAKI_ENABLE_TESTS OFF CACHE BOOL "" FORCE)
set(CHIAKI_ENABLE_CLI OFF CACHE BOOL "" FORCE)
set(CHIAKI_ENABLE_GUI OFF CACHE BOOL "" FORCE)
set(CHIAKI_ENABLE_SETSU OFF CACHE STRING "" FORCE)
set(CHIAKI_ENABLE_STEAMDECK_NATIVE OFF CACHE STRING "" FORCE)
set(CHIAKI_ENABLE_STEAM_SHORTCUT OFF CACHE BOOL "" FORCE)
set(CHIAKI_ENABLE_SPEEX OFF CACHE STRING "" FORCE)
set(CHIAKI_ENABLE_FFMPEG_DECODER OFF CACHE STRING "" FORCE)
set(CHIAKI_ENABLE_PI_DECODER OFF CACHE STRING "" FORCE)
set(CHIAKI_LIB_ENABLE_OPUS OFF CACHE BOOL "" FORCE)
set(CHIAKI_USE_SYSTEM_JERASURE OFF CACHE STRING "" FORCE)
set(CHIAKI_USE_SYSTEM_NANOPB OFF CACHE STRING "" FORCE)
set(CHIAKI_USE_SYSTEM_CURL OFF CACHE STRING "" FORCE)
# mbedTLS (fetched) instead of OpenSSL: one CMake build on every OS. curl is only for internet play, so no TLS there.
set(CHIAKI_LIB_ENABLE_MBEDTLS ON CACHE BOOL "" FORCE)
set(CHIAKI_LIB_MBEDTLS_EXTERNAL_PROJECT ON CACHE BOOL "" FORCE)
set(MBEDTLS_FATAL_WARNINGS OFF CACHE BOOL "" FORCE)
set(CHIAKI_LIB_OPENSSL_EXTERNAL_PROJECT OFF CACHE BOOL "" FORCE)
set(CURL_ENABLE_SSL OFF CACHE BOOL "" FORCE)
set(CURL_USE_LIBPSL OFF CACHE BOOL "" FORCE)
set(USE_LIBIDN2 OFF CACHE BOOL "" FORCE)
set(CURL_USE_LIBSSH2 OFF CACHE BOOL "" FORCE)
set(CURL_ZLIB OFF CACHE STRING "" FORCE)
set(CURL_BROTLI OFF CACHE STRING "" FORCE)
set(CURL_ZSTD OFF CACHE STRING "" FORCE)
set(USE_NGHTTP2 OFF CACHE BOOL "" FORCE)
set(CHIAKI_LIB_JSONC_EXTERNAL_PROJECT ON CACHE BOOL "" FORCE)
set(CHIAKI_LIB_MINIUPNPC_EXTERNAL_PROJECT ON CACHE BOOL "" FORCE)
add_subdirectory("${CHIAKI_DIR}" chiaki EXCLUDE_FROM_ALL)

# curl and json-c share the cached SIZEOF_SSIZE_T check; with it set for json-c (above), curl no longer defines
# ssize_t itself on MSVC, so give it to curl's own sources (curl's public headers do not use it).
if(MSVC)
    if(CMAKE_SIZEOF_VOID_P EQUAL 8)
        set(LUGHA_SSIZE_T __int64)
    else()
        set(LUGHA_SSIZE_T int)
    endif()
    foreach(t libcurl_object libcurl_static libcurl_shared)
        if(TARGET ${t})
            target_compile_definitions(${t} PRIVATE ssize_t=${LUGHA_SSIZE_T})
        endif()
    endforeach()
    # Two libraries call functions whose headers they never include on Windows; clang-cl refuses that.
    if(TARGET gf_complete)
        target_compile_options(gf_complete PRIVATE /FIintrin.h)  # __cpuidex
    endif()
    if(TARGET json-c)
        target_compile_options(json-c PRIVATE /FIio.h)  # read, write, open, close
    endif()
endif()

add_library(lugha_rp STATIC lugha_rp.c)
target_include_directories(lugha_rp PUBLIC "${CMAKE_CURRENT_SOURCE_DIR}")
target_link_libraries(lugha_rp PUBLIC chiaki-lib)
# chiaki's headers change struct sizes with this (set by chiaki's own CMakeLists for its directory only); without
# it here, chiaki and this file disagree on sizeof(ChiakiSession) and the session overwrites our fields.
target_compile_definitions(lugha_rp PRIVATE CHIAKI_LIB_ENABLE_MBEDTLS)

add_executable(lugha_rp_probe EXCLUDE_FROM_ALL probe.c)
target_link_libraries(lugha_rp_probe lugha_rp)
