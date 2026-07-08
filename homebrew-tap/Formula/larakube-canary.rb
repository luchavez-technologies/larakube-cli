class LarakubeCanary < Formula
  desc "Kubernetes for Laravel — bleeding-edge builds from the tip of main (unstable)"
  homepage "https://larakube.luchtech.dev"
  version "CANARY_VERSION"
  license "MIT"

  # Republished under the same "canary" release tag on every push to main, so
  # the binary's bytes at this URL change without the URL itself changing.
  # sha256 :no_check is Homebrew's documented escape hatch for exactly that —
  # a pinned checksum would just go stale and break every subsequent install.
  # The `version` string above IS refreshed every push (to the short commit
  # SHA) by CI specifically so Homebrew's own cache treats each canary build
  # as new and re-downloads instead of serving a stale cached copy.
  on_macos do
    on_arm do
      url "https://github.com/luchavez-technologies/larakube-cli/releases/download/canary/larakube-mac-arm"
      sha256 :no_check
    end
    on_intel do
      url "https://github.com/luchavez-technologies/larakube-cli/releases/download/canary/larakube-mac-x64"
      sha256 :no_check
    end
  end

  def install
    if Hardware::CPU.arm?
      bin.install "larakube-mac-arm" => "larakube-canary"
    else
      bin.install "larakube-mac-x64" => "larakube-canary"
    end
  end

  def caveats
    <<~EOS
      This is the CANARY (bleeding-edge, unstable) build of LaraKube CLI,
      built from the latest commit on main — it may be broken. It installs
      as `larakube-canary`, side by side with the stable `larakube` formula,
      so it never touches your stable install.

      To pull the latest canary build:
        brew reinstall larakube-canary

      For the stable release:
        brew install luchavez-technologies/larakube/larakube
    EOS
  end

  test do
    system "#{bin}/larakube-canary", "--version"
  end
end
